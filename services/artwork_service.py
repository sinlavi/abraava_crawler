import os
import time
import io
import logging
import asyncio
import aiohttp
from typing import Optional, Union, Dict, Any, List, Tuple
from telegram import Bot, Message
from core.logger import logger
from core.http_client import HttpClient
from services.api_client import APIClient
from crawlers.itunes import set_mirror, get_attachments
from crawlers.youtube import get_artist_image, get_track_image
from utils.helpers import get_high_res_artwork
from utils.image_utils import crop_to_square

class ArtworkService:
    def __init__(self, api_client: APIClient, user_settings_service=None):
        self.api_client = api_client
        self.user_settings_service = user_settings_service
        self.auto_download_mode = {} # user_id -> end_timestamp

    def _in_auto_download(self, user_id: int) -> bool:
        ts = self.auto_download_mode.get(user_id, 0)
        return time.time() < ts

    async def get_cached_artwork_url(self, entity_type: str, entity_id: Union[int, str]) -> Optional[str]:
        try:
            if not entity_id: return None

            attachments = await get_attachments(entity_type, str(entity_id))
            if attachments and isinstance(attachments, dict):
                artwork_list = attachments.get('artworkUrls', [])
                if not artwork_list and 'artworkUrl' in attachments: # Fallback
                     artwork_list = [attachments['artworkUrl']]

                for art in artwork_list:
                    cached_url = art.get('url') if isinstance(art, dict) else art
                    if cached_url and 'bot<token>/' in cached_url:
                        return cached_url.split('bot<token>/')[-1]
                    return cached_url
            return None
        except Exception as e:
            logger.error(f"Error getting cached artwork: {e}")
            return None

    async def set_artwork_mirror(self, entity_type: str, entity_id: Union[int, str], file_id: str) -> bool:
        try:
            if not entity_id or not file_id: return False

            # Using generic bot<token> placeholder
            artwork_url = f'https://api.telegram.org/file/bot<token>/{file_id}'
            result = await set_mirror(entity_type, str(entity_id), 'artworkUrl', artwork_url)
            return bool(result)
        except Exception as e:
            logger.error(f"Error setting artwork mirror: {e}")
            return False

    async def get_artwork_for_display(self, entity_type: str, entity_id: int,
                                       artwork_url: Optional[str] = None,
                                       user_id: Optional[int] = None,
                                       entity_name: str = None) -> Optional[Union[str, bytes]]:
        logger.info(f"Retrieving artwork for {entity_type} {entity_id} ({entity_name}) for user {user_id}")

        # settings removed, defaulting show_artwork to True
        show_artwork = True

        if not show_artwork:
            logger.info(f"Artwork display is disabled")
            return None

        cached_file_id = await self.get_cached_artwork_url(entity_type, entity_id)
        if cached_file_id and not self._in_auto_download(user_id):
            logger.info(f"Using cached artwork file_id: {cached_file_id}")
            return cached_file_id

        # Fallback for artist artwork from YouTube Music
        final_url = artwork_url
        if entity_type == "artist" and not final_url and entity_name:
            final_url = get_artist_image(entity_name)

        if final_url:
            try:
                session = await HttpClient.get_session()
                async with session.get(final_url, timeout=30) as resp:
                    if resp.status == 200:
                        artwork_bytes = await resp.read()
                        if isinstance(entity_id, str) and entity_id.startswith(("yt_", "sc_")):
                            artwork_bytes = crop_to_square(artwork_bytes)
                        return artwork_bytes
            except Exception as e:
                logger.error(f"Error downloading artwork: {e}")
        return None

    async def send_artwork_photo(self, bot: Bot, chat_id: int, artwork_data: Union[str, bytes],
                                  caption: str, reply_markup=None,
                                  entity_type: str = None, entity_id: int = None,
                                  user_id: int = None, silent: bool = False):
        try:
            from utils.messages import FOOTER

            try:
                if not silent:
                    await bot.send_chat_action(chat_id, "upload_photo")

                if isinstance(artwork_data, str):
                    msg = await bot.send_photo(chat_id, photo=artwork_data, caption=f"{caption}{FOOTER}")
                else:
                    photo_io = io.BytesIO(artwork_data)
                    photo_io.name = "artwork.jpg"
                    msg = await bot.send_photo(chat_id, photo=photo_io, caption=f"{caption}{FOOTER}")

                    if msg and msg.photo and entity_type and entity_id:
                        # telegram.PhotoSize uses file_id
                        file_id = msg.photo[-1].file_id
                        await self.set_artwork_mirror(entity_type, entity_id, file_id)
                return msg
            except Exception as e:
                logger.warning(f"Failed to send artwork: {e}")
                return None
        except Exception as e:
            logger.error(f"Failed in send_artwork_photo helper: {e}")
            raise

    async def get_artwork_bytes(self, entity_id: Union[int, str], artwork_url100: str, title: str = None, artist: str = None):
        if not entity_id:
            return None

        cache_dir = "cache/artworks"
        os.makedirs(cache_dir, exist_ok=True)
        cache_path = os.path.join(cache_dir, f"{entity_id}.jpg")

        # 1. Check local cache
        if os.path.exists(cache_path):
            logger.info(f"Using locally cached artwork for {entity_id}")
            try:
                with open(cache_path, "rb") as f:
                    return f.read()
            except Exception as e:
                logger.error(f"Error reading artwork cache: {e}")

        # 2. Try download from artwork_url100
        artwork_bytes = None
        url = get_high_res_artwork(artwork_url100, 600)
        if url:
            try:
                session = await HttpClient.get_session()
                async with session.get(url, timeout=30) as resp:
                    if resp.status == 200:
                        artwork_bytes = await resp.read()
                    else:
                        logger.warning(f"Failed to download artwork from iTunes URL: HTTP {resp.status}")
            except Exception as e:
                logger.warning(f"Failed to download artwork from iTunes URL: {e}")

        # 3. Fallback to YouTube Music
        if not artwork_bytes and title and artist:
            logger.info(f"Falling back to YouTube Music artwork for {title} - {artist}")
            yt_artwork_url = get_track_image(title, artist)
            if yt_artwork_url:
                try:
                    session = await HttpClient.get_session()
                    async with session.get(yt_artwork_url, timeout=30) as resp:
                        if resp.status == 200:
                            artwork_bytes = await resp.read()
                        else:
                            logger.warning(f"Failed to download artwork from YouTube Music: HTTP {resp.status}")
                except Exception as e:
                    logger.warning(f"Failed to download artwork from YouTube Music: {e}")

        if artwork_bytes:
            if isinstance(entity_id, str) and entity_id.startswith(("yt_", "sc_")):
                artwork_bytes = crop_to_square(artwork_bytes)

            # Save to cache
            try:
                with open(cache_path, "wb") as f:
                    f.write(artwork_bytes)
            except Exception as e:
                logger.error(f"Failed to save artwork to cache: {e}")

            return artwork_bytes

        return None
