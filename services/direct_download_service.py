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
        opts = {
            'format': 'bestaudio/best',
            'quiet': True,
            'no_check_certificate': True,
            'http_headers': self._get_random_headers(),
            'retries': 5
        }

        if output_dir:
            opts['outtmpl'] = f'{output_dir}/%(title)s.%(ext)s'
            opts['postprocessors'] = [{
                'key': 'FFmpegExtractAudio',
                'preferredcodec': 'mp3',
                'preferredquality': quality,
            }]
        else:
            opts['skip_download'] = True

        proxy = self._get_proxy()

        if method == 2 and proxy:
            opts['proxy'] = proxy
        elif method == 3:
            if "youtube.com" in url or "youtu.be" in url:
                opts['extractor_args'] = {"youtube": {"player_client": ["web", "mweb", "android_vr"]}}
            if proxy: opts['proxy'] = proxy

        return opts

    async def get_metadata(self, url):
        for method in [1, 2, 3]:
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
                continue
        return None

    async def _update_status(self, chat_id, msg, text, reply_markup=None):
        await safe_delete(msg)
        return await send_message(self.bot, chat_id, text)

    async def download_direct(self, chat_id, url, user_id, quality="192"):
        status_msg = await send_message(self.bot, chat_id, f"⏳ *Starting download...*")

        unique_id = uuid.uuid4().hex
        temp_dir = os.path.join(os.getcwd(), "downloads", unique_id)
        os.makedirs(temp_dir, exist_ok=True)

        success = False
        track_data = {}
        mp3_path = None

        try:
            for method in [1, 2, 3]:
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
                        }

                        files = list(Path(temp_dir).glob("*.mp3"))
                        if files:
                            mp3_path = files[0]
                            success = True
                            break
                except Exception as e:
                    logger.warning(f"Download method {method} failed: {e}")
                    continue

            if success and mp3_path:
                status_msg = await self._update_status(chat_id, status_msg, "☁️ *Preparing file...*")
                # For direct download, track_id is not available, using unique_id as fallback key
                t_id = f"direct_{unique_id}"
                lyrics_dict = await lyrics_service.get_lyrics(t_id, track_data.get("trackName", ""), track_data.get("artistName", ""), track_data.get("collectionName"))
                lyrics_to_tag = (lyrics_dict.get("synced") or lyrics_dict.get("plain")) if lyrics_dict else None
                self.tagging_service.tag_mp3(mp3_path, track_data, lyrics=lyrics_to_tag)

                track_name = track_data['trackName']

                fields = {
                    "🎵 Track Name": track_name,
                    "🎤 Artist Name": track_data.get('artistName'),
                    "💿 Album Name": track_data.get('collectionName'),
                    "📀 Download Quality": f"{quality} kbps"
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
                await safe_delete(status_msg)
                return status_msg, True
            else:
                status_msg = await self._update_status(chat_id, status_msg, "❌ Download failed.")
                return status_msg, False

        except Exception as e:
            logger.error(f"Direct download service error: {e}")
            status_msg = await self._update_status(chat_id, status_msg, f"❌ Error: {str(e)[:50]}")
            return status_msg, False
        finally:
            shutil.rmtree(temp_dir, ignore_errors=True)
