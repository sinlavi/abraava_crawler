from services.lyrics_service import lyrics_service
import asyncio
import os
import shutil
import uuid
import yt_dlp
import random
from pathlib import Path
from core.logger import logger
from utils.messages import send_message, edit_message, safe_delete
from core.config import PROXY, FOOTER
from telegram import Bot
from core.http_client import HttpClient
from utils.image_utils import crop_to_square
from utils.audio_utils import convert_bitrate

# ── User‑agent list (Same as youtube crawler) ────────────────────
USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14.4; rv:125.0) Gecko/20100101 Firefox/125.0",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.0.0",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_4_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4.1 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.82 Mobile Safari/537.36"
]

class DirectDownloadService:
    def __init__(self, bot, tagging_service):
        self.bot = bot
        self.tagging_service = tagging_service

    def _get_random_headers(self) -> dict:
        return {
            "User-Agent": random.choice(USER_AGENTS),
            "Accept-Language": "en-US,en;q=0.9",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
        }

    def _get_proxy(self):
        """Return SOCKS5 proxy URL from config or check for local WARP/Dante."""
        if PROXY: return PROXY

        import socket
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.settimeout(0.5)
        try:
            if s.connect_ex(("127.0.0.1", 1080)) == 0:
                return "socks5://127.0.0.1:1080"
        except: pass
        finally: s.close()
        return None

    def _build_opts(self, url, output_dir=None, quality="192", method=1):
        from crawlers.youtube import _build_opts as build_yt_opts

        # Reuse robust options from youtube crawler
        # Passing quality as integer, _build_opts handles string conversion for preferredquality
        q_val = int(quality) if str(quality).isdigit() else 192
        opts = build_yt_opts(method, output_dir or "", q_val)

        if not output_dir:
            opts['skip_download'] = True
            opts['extract_flat'] = False

        return opts

    async def get_metadata(self, url):
        methods = [8, 2, 3, 4, 5, 6, 7, 1]
        last_error = None
        for method in methods:
            opts = self._build_opts(url, method=method)
            try:
                loop = asyncio.get_event_loop()
                with yt_dlp.YoutubeDL(opts) as ydl:
                    info = await loop.run_in_executor(None, lambda: ydl.extract_info(url, download=False))
                    return {
                        'title': info.get('title', 'Unknown'),
                        'uploader': info.get('uploader', info.get('artist', 'Unknown')),
                        'album': info.get('album', ''),
                        'url': url,
                        'upload_date': info.get('upload_date', ''),
                        'thumbnail': info.get('thumbnail'),
                        'duration': info.get('duration')
                    }
            except Exception as e:
                logger.debug(f"Metadata fetch failed with method {method}: {e}")
                last_error = e
                if "IncompleteRead" in str(e) or "Status code 404" in str(e) or "this video is unavailable" in str(e).lower():
                    logger.warning(f"Video {url} confirmed not found or unavailable.")
                    return None # Confirmed not found
                continue

        msg = f"Ultimate technical failure fetching metadata for {url}: {last_error}"
        logger.error(msg)
        raise Exception(msg)

    async def _update_status(self, chat_id, msg, text, reply_markup=None):
        await safe_delete(msg)
        return await send_message(self.bot, chat_id, text)

    async def download_direct(self, chat_id, url, user_id, quality="192"):
        status_msg = await send_message(self.bot, chat_id, f"⏳ *در حال شروع دانلود...*")

        unique_id = uuid.uuid4().hex
        temp_dir = os.path.join(os.getcwd(), "downloads", unique_id)
        os.makedirs(temp_dir, exist_ok=True)

        success = False
        track_data = {}
        mp3_path = None

        methods = [8, 2, 3, 4, 5, 6, 7, 1]
        last_error = None
        try:
            for method in methods:
                opts = self._build_opts(url, output_dir=temp_dir, quality=quality, method=method)
                try:
                    loop = asyncio.get_event_loop()
                    await self.bot.send_chat_action(chat_id, "record_voice")
                    with yt_dlp.YoutubeDL(opts) as ydl:
                        info = await loop.run_in_executor(None, lambda: ydl.extract_info(url, download=True))

                        track_data = {
                            'trackName': info.get('title', 'Unknown'),
                            'artistName': info.get('uploader', info.get('artist', 'Unknown')),
                            'collectionName': info.get('album', ''),
                            'releaseDate': info.get('upload_date', '')[:4],
                            'thumbnail': info.get('thumbnail')
                        }

                        files = list(Path(temp_dir).glob("*.mp3"))
                        if files:
                            mp3_path = files[0]
                            success = True
                            break
                except Exception as e:
                    logger.warning(f"Download method {method} failed: {e}")
                    last_error = e
                    if "IncompleteRead" in str(e) or "Status code 404" in str(e) or "this video is unavailable" in str(e).lower():
                        status_msg = await self._update_status(chat_id, status_msg, "❌ ویدیو یافت نشد یا در دسترس نیست.")
                        return status_msg, False
                    continue

            if not success:
                 msg = f"Ultimate technical failure downloading direct URL {url}: {last_error}"
                 logger.error(msg)
                 raise Exception(msg)

            if success and mp3_path:
                status_msg = await self._update_status(chat_id, status_msg, "☁️ *در حال آماده‌سازی فایل...*")

                # Download and crop artwork if available
                cover_bytes = None
                thumbnail_url = track_data.get('thumbnail')
                if thumbnail_url:
                    # Use multi-method request for better reliability
                    data, status, is_tech_err = await HttpClient.request_with_methods("GET", thumbnail_url)
                    if status == 200 and data:
                        cover_bytes = data
                        cover_bytes = crop_to_square(cover_bytes)
                    elif is_tech_err:
                        msg = f"Technical error downloading direct artwork from {thumbnail_url} (Status: {status})"
                        logger.error(msg)
                        raise Exception(msg)

                # For direct download, track_id is not available, using unique_id as fallback key
                t_id = f"direct_{unique_id}"
                lyrics_dict = await lyrics_service.get_lyrics(t_id, track_data.get("trackName", ""), track_data.get("artistName", ""), track_data.get("collectionName"))

                if lyrics_dict is None:
                     msg = f"Technical error fetching lyrics for {t_id}"
                     logger.error(msg)
                     raise Exception(msg)

                # Check for synced lyrics first
                lyrics_to_tag = None
                if lyrics_dict:
                    lyrics_to_tag = lyrics_dict.get("synced") or lyrics_dict.get("plain")

                self.tagging_service.tag_mp3(mp3_path, track_data, cover_bytes=cover_bytes, lyrics=lyrics_to_tag)

                track_name = track_data['trackName']

                fields = {
                    "🎵 نام آهنگ": track_name,
                    "🎤 نام هنرمند": track_data.get('artistName'),
                    "💿 نام آلبوم": track_data.get('collectionName'),
                    "📀 کیفیت دانلود": f"{quality} kbps"
                }

                caption_lines = []
                for k, v in fields.items():
                    if v and str(v).strip() and "Unknown" not in str(v):
                        caption_lines.append(f"{k}: {v}")

                caption = "\n".join(caption_lines)

                with open(mp3_path, 'rb') as f:
                    await self.bot.send_chat_action(chat_id, "upload_voice")
                    logger.info(f"Direct uploading audio: {track_data.get('trackName')} ({quality}kbps)")
                    await self.bot.send_audio(chat_id, audio=f, caption=f"{caption}{FOOTER}")

                # DUAL UPLOAD for direct downloads
                if str(quality) == "320":
                    try:
                        mp3_192_path = str(mp3_path).replace(".mp3", "_192.mp3")
                        if convert_bitrate(Path(mp3_path), Path(mp3_192_path), "192"):
                            self.tagging_service.tag_mp3(mp3_192_path, track_data, cover_bytes=cover_bytes, lyrics=lyrics_to_tag)

                            fields_192 = {
                                "🎵 نام آهنگ": track_name,
                                "🎤 نام هنرمند": track_data.get('artistName'),
                                "💿 نام آلبوم": track_data.get('collectionName'),
                                "📀 کیفیت دانلود": "192 kbps"
                            }
                            caption_192 = "\n".join([f"{k}: {v}" for k, v in fields_192.items() if v and "Unknown" not in str(v)])

                            with open(mp3_192_path, 'rb') as f192:
                                await self.bot.send_chat_action(chat_id, "upload_voice")
                                logger.info(f"Direct uploading converted 192kbps audio: {track_data.get('trackName')}")
                                await self.bot.send_audio(chat_id, audio=f192, caption=f"{caption_192}{FOOTER}")
                    except Exception as e:
                        logger.error(f"Failed dual upload in direct download: {e}")
                await safe_delete(status_msg)
                return status_msg, True
            else:
                status_msg = await self._update_status(chat_id, status_msg, "❌ دانلود با خطا مواجه شد.")
                return status_msg, False

        except Exception as e:
            logger.error(f"Direct download service error: {e}")
            status_msg = await self._update_status(chat_id, status_msg, f"❌ خطا: {str(e)[:50]}")
            return status_msg, False
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)
