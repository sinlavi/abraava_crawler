from core.config import BOT_TOKEN, INFO_CHANNEL_ID, OFFLINE_MODE, API_BASE_URL, API_TOKEN, PROXY
import os

# Set global proxy environment variables
if PROXY:
    os.environ["HTTP_PROXY"] = PROXY
    os.environ["HTTPS_PROXY"] = PROXY

from balethon import Client
from core.logger import logger
from core.http_client import HttpClient

from services.api_client import APIClient
from services.user_settings_service import UserSettingsService
from services.artwork_service import ArtworkService
from services.rate_limiter import DownloadRateLimiter
from services.tracker import AlbumDownloadTracker
from services.tagging_service import TaggingService
from services.error_notifier import BaleUploadErrorNotifier
from services.download_service import DownloadService
from services.lyrics_service import lyrics_service
from crawlers.itunes import get_download_queue, update_download_status

import asyncio
import signal
import sys
import time

# Hardcoded channel ID
TARGET_CHANNEL_ID = 6053683389

async def run_crawler():
    # Initialize Services
    api_client = APIClient(API_BASE_URL, API_TOKEN)
    user_settings_service = UserSettingsService(api_client)
    artwork_service = ArtworkService(api_client, user_settings_service)
    download_rate_limiter = DownloadRateLimiter()
    album_tracker = AlbumDownloadTracker(api_client)
    tagging_service = TaggingService()
    error_notifier = BaleUploadErrorNotifier(api_client)

    bot = Client(token=BOT_TOKEN, proxy=PROXY)

    download_service = DownloadService(bot, api_client, user_settings_service, artwork_service,
                                       tagging_service, error_notifier, album_tracker, download_rate_limiter)

    await lyrics_service.init_db()
    logger.info("ABRAAVA Crawler initialized and starting poll loop...")

    while True:
        try:
            # Poll for pending downloads
            queue_resp = await get_download_queue(status="pending", limit=5)
            if not queue_resp or not queue_resp.get("success") or not queue_resp.get("items"):
                logger.debug("No pending downloads found. Sleeping...")
                await asyncio.sleep(30)
                continue

            items = queue_resp.get("items", [])
            for item in items:
                download_id = item.get("download_id")
                track_id = item.get("trackId")
                quality = item.get("quality", "192")

                logger.info(f"Processing download {download_id} for track {track_id}")

                # Update status to downloading
                await update_download_status(download_id, "downloading")

                # Use a dummy user_id (Admin ID)
                user_id = 234591600

                # Download and send
                try:
                    _, success = await download_service.download_and_send_track(
                        chat_id=TARGET_CHANNEL_ID,
                        track_id=track_id,
                        user_id=user_id,
                        selected_quality=quality,
                        silent=True
                    )

                    if success:
                        logger.info(f"Successfully processed download {download_id}")
                        await update_download_status(download_id, "completed")
                    else:
                        logger.error(f"Failed to process download {download_id}")
                        await update_download_status(download_id, "failed", error_message="Download or upload failed")
                except Exception as e:
                    logger.exception(f"Error processing download {download_id}: {e}")
                    await update_download_status(download_id, "failed", error_message=str(e))

                # Small delay between tracks to avoid rate limiting
                await asyncio.sleep(5)

        except Exception as e:
            logger.exception(f"Crawler loop error: {e}")
            await asyncio.sleep(60)

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
