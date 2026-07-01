from core.config import TG_TOKEN, INFO_CHANNEL_ID, OFFLINE_MODE, API_BASE_URL, API_TOKEN, PROXY, TARGET_CHAT_ID
import os

# Set global proxy environment variables
if PROXY:
    os.environ["HTTP_PROXY"] = PROXY
    os.environ["HTTPS_PROXY"] = PROXY

from telegram import Bot
from telegram.request import HTTPXRequest
from core.logger import logger
from core.http_client import HttpClient

from utils.helpers import get_high_res_artwork
from crawlers.utils import get_track
from bot.handlers.preview import send_voice_preview
from services.api_client import APIClient
from services.artwork_service import ArtworkService
from services.rate_limiter import DownloadRateLimiter
from services.tracker import AlbumDownloadTracker
from services.tagging_service import TaggingService
from services.error_notifier import BaleUploadErrorNotifier
from services.download_service import DownloadService
from services.lyrics_service import lyrics_service
from crawlers.itunes import get_download_queue, update_download_status, reset_stuck_downloads

import asyncio
import signal
import sys
import time

# Environment variables for sharding and runtime control
INSTANCE_ID = int(os.getenv("INSTANCE_ID", "1"))
TOTAL_INSTANCES = int(os.getenv("TOTAL_INSTANCES", "1"))
MAX_RUNTIME = int(os.getenv("MAX_RUNTIME", 5.5 * 3600)) # 5.5 hours default
START_TIME = time.time()

# Target Chat ID from config
TARGET_CHANNEL_ID = TARGET_CHAT_ID

# Lock to prevent concurrent artwork uploads for the same collection
artwork_lock = asyncio.Lock()

async def process_queue_item(bot, item, download_service, artwork_service, user_id, active_tasks):
    download_id = item.get("download_id")
    track_id = item.get("trackId")
    quality = item.get("quality", "192")

    logger.info(f"Processing download {download_id} for track {track_id}")

    try:
        max_attempts = 3
        for attempt in range(1, max_attempts + 1):
            # Update status to downloading
            await update_download_status(download_id, "downloading", percent=0)

            # Process track
            try:
                # 1. Fetch metadata
                track_data = await get_track(track_id)
                if not track_data or not track_data.get("results"):
                    raise Exception("Track data not found")

                track = track_data["results"][0]
                await update_download_status(download_id, "downloading", percent=5)

                # 2. Upload Artwork
                artwork_url = get_high_res_artwork(track.get("artworkUrl100"), 400)
                if artwork_url:
                    coll_id = track.get("collectionId") or track_id
                    # Use lock to prevent duplicate concurrent uploads for the same collection
                    async with artwork_lock:
                        # We need to adapt ArtworkService or just use it as is if it doesn't strictly depend on balethon for get_cached_artwork_url
                        if not await artwork_service.get_cached_artwork_url("collection", coll_id):
                            artwork_bytes = await artwork_service.get_artwork_for_display("collection", coll_id, artwork_url, user_id)
                            if artwork_bytes:
                                caption = f"🖼 *کاور آهنگ:* {track.get('trackName')} - {track.get('artistName')}"
                                # Adapt send_artwork_photo if needed, but for now we follow the goal of hardcoding target chat
                                await bot.send_photo(TARGET_CHANNEL_ID, photo=artwork_bytes, caption=caption)

                await update_download_status(download_id, "downloading", percent=10)

                # 3. Upload Preview
                if track.get("previewUrl"):
                    await send_voice_preview(bot, TARGET_CHANNEL_ID, track_id, user_id, silent=True)

                await update_download_status(download_id, "downloading", percent=15)

                # 4. Download and Send Audio
                _, success = await download_service.download_and_send_track(
                    chat_id=TARGET_CHANNEL_ID,
                    track_id=track_id,
                    user_id=user_id,
                    selected_quality=quality,
                    silent=True,
                    download_id=download_id
                )

                if success:
                    logger.info(f"Successfully processed download {download_id} on attempt {attempt}")
                    await update_download_status(download_id, "completed", percent=100)
                    return
                else:
                    raise Exception("Download or upload failed")

            except Exception as e:
                logger.warning(f"Error processing download {download_id} (Attempt {attempt}/{max_attempts}): {e}")
                if attempt < max_attempts:
                    # Wait before retrying (exponential backoff or fixed delay)
                    await asyncio.sleep(5 * attempt)
                else:
                    logger.error(f"Ultimate failure processing download {download_id}: {e}")
                    await update_download_status(download_id, "failed", error_message=str(e))
    finally:
        active_tasks.discard(download_id)


async def run_crawler():
    # Initialize Services
    api_client = APIClient(API_BASE_URL, API_TOKEN)
    # user_settings_service removed
    artwork_service = ArtworkService(api_client, None) # Passed None for user_settings_service
    download_rate_limiter = DownloadRateLimiter()
    album_tracker = AlbumDownloadTracker(api_client)
    tagging_service = TaggingService()
    error_notifier = BaleUploadErrorNotifier(api_client)

    request = None
    if PROXY:
        request = HTTPXRequest(proxy=PROXY)

    bot = Bot(token=TG_TOKEN, request=request)

    async with bot:
        download_service = DownloadService(bot, api_client, artwork_service,
                                           tagging_service, error_notifier, album_tracker, download_rate_limiter)

        logger.info(f"ABRAAVA Crawler Instance {INSTANCE_ID}/{TOTAL_INSTANCES} initialized and starting poll loop...")

        # Only the primary instance resets stuck downloads to avoid race conditions
        if INSTANCE_ID == 1:
            await reset_stuck_downloads()

        active_tasks = set()
        consecutive_tech_errors = 0

        while True:
            # Check for runtime limit
            if time.time() - START_TIME > MAX_RUNTIME:
                logger.info("Max runtime reached. Shutting down crawler gracefully...")
                # Wait for remaining tasks
                if active_tasks:
                    logger.info(f"Waiting for {len(active_tasks)} remaining tasks to complete...")
                    # Give them some time but not forever
                    for _ in range(30):
                        if not active_tasks: break
                        await asyncio.sleep(30)
                break

            try:
                # Poll for pending downloads
                queue_resp = await get_download_queue(status="pending", limit=100)

                # Check for technical error (get_download_queue returns None on tech error)
                if queue_resp is None:
                    consecutive_tech_errors += 1
                    logger.warning(f"Technical error encountered ({consecutive_tech_errors}/10)")
                    if consecutive_tech_errors >= 10:
                        logger.critical("Too many consecutive technical errors. Exiting for workflow restart...")
                        sys.exit(1)
                    await asyncio.sleep(50) # Conservative sleep on tech error
                    continue

                # Reset error counter on any non-technical response (even if success=False or no items)
                consecutive_tech_errors = 0

                if not queue_resp.get("success") or not queue_resp.get("items"):
                    logger.debug("No pending downloads found. Sleeping...")
                    await asyncio.sleep(20) # Conservative polling frequency
                    continue

                items = queue_resp.get("items", [])

                # Use a dummy user_id (Admin ID)
                user_id = 234591600

                for item in items:
                    download_id = item.get("download_id")

                    # Sharding logic: only process items that belong to this instance
                    if download_id % TOTAL_INSTANCES != (INSTANCE_ID - 1):
                        continue

                    if download_id in active_tasks:
                        continue

                    active_tasks.add(download_id)
                    # Launch task without waiting
                    asyncio.create_task(process_queue_item(bot, item, download_service, artwork_service, user_id, active_tasks))

                    # Stagger task start slightly to prevent rate limiting
                    await asyncio.sleep(0.05)

                # Small delay before next poll if we have many active tasks to avoid overwhelming
                if len(active_tasks) > 50:
                    await asyncio.sleep(35)
                else:
                    await asyncio.sleep(30)

            except Exception as e:
                logger.exception(f"Crawler loop error: {e}")
                await asyncio.sleep(30)

def signal_handler(sig, frame):
    sys.exit(0)

async def main():
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)

    try:
        await run_crawler()
    finally:
        await HttpClient.close()

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
