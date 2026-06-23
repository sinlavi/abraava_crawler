import aiosqlite
import asyncio
from ytmusicapi import YTMusic
from core.config import CACHE_DIR, PROXY
import os
import logging
from concurrent.futures import ThreadPoolExecutor
from crawlers.youtube import search_youtube_track
from crawlers.itunes import get_lyrics as get_3rah_lyrics, set_lyrics as set_3rah_lyrics
from pathlib import Path
import http.cookiejar

logger = logging.getLogger("MUSICMAN:LYRICS_SERVICE")

def _load_cookies_as_header(cookie_file):
    """Load Netscape cookies.txt and return a Cookie header string."""
    try:
        if not os.path.exists(cookie_file):
            return None
        cj = http.cookiejar.MozillaCookieJar(cookie_file)
        cj.load(ignore_discard=True, ignore_expires=True)
        cookies = []
        for cookie in cj:
            cookies.append(f"{cookie.name}={cookie.value}")
        return "; ".join(cookies)
    except Exception as e:
        logger.error(f"Error loading cookies from {cookie_file}: {e}")
        return None

class LyricsService:
    def __init__(self):
        cookies_path = "cookies.txt"
        cookie_header = _load_cookies_as_header(cookies_path)

        self.ytm = YTMusic(proxies={"https": PROXY, "http": PROXY})
        if cookie_header:
            logger.info(f"Adding Cookie header from: {cookies_path}")
            self.ytm.headers["Cookie"] = cookie_header

        self.db_path = os.path.join(CACHE_DIR, "lyrics.db")
        self._executor = ThreadPoolExecutor(max_workers=5)

        if not os.path.exists(CACHE_DIR):
            os.makedirs(CACHE_DIR)

    async def init_db(self):
        async with aiosqlite.connect(self.db_path) as db:
            # Check if synced_lyrics column exists (for migration)
            cursor = await db.execute("PRAGMA table_info(lyrics)")
            columns = await cursor.fetchall()
            has_synced = any(col[1] == 'synced_lyrics' for col in columns)

            if not has_synced and columns:
                # Simple migration: drop and recreate since it's just a cache
                await db.execute("DROP TABLE lyrics")

            await db.execute("""
                CREATE TABLE IF NOT EXISTS lyrics (
                    track_id TEXT PRIMARY KEY,
                    synced_lyrics TEXT,
                    plain_lyrics TEXT,
                    title TEXT,
                    artist TEXT
                )
            """)
            await db.commit()

    async def get_lyrics(self, track_id, title, artist, album=None, duration_ms=0):
        # Ensure track_id is a string
        track_id = str(track_id)
        logger.info(f"Retrieving lyrics for: {title} - {artist} (ID: {track_id}, Duration: {duration_ms}ms)")

        # 1. Check Local Cache
        cached_lyrics = await self._get_cached_lyrics(track_id)
        if cached_lyrics and (cached_lyrics.get("synced") or "Instrumental" in str(cached_lyrics.get("plain"))):
            logger.info(f"Synced or Instrumental lyrics found in local cache for {track_id}")
            return cached_lyrics

        # 2. Check 3rah API (Central Cache)
        central_lyrics = None
        try:
            logger.info(f"Checking 3rah central API for lyrics (ID: {track_id})")
            central_lyrics = await get_3rah_lyrics(track_id)
            if central_lyrics and (central_lyrics.get("synced") or "Instrumental" in str(central_lyrics.get("plain"))):
                logger.info(f"Synced or Instrumental lyrics found in 3rah API for {track_id}")
                # Cache locally for faster subsequent access
                await self._cache_lyrics(track_id, central_lyrics, title, artist, push_to_central=False)
                return central_lyrics
        except Exception as e:
            logger.error(f"Error fetching lyrics from 3rah API: {e}")

        # Fallback to whatever we have if LRCLIB fails later
        best_so_far = cached_lyrics or central_lyrics

        # 3. Fetch from LRCLIB
        logger.info(f"Crawling LRCLIB for lyrics: {title} - {artist}")
        lyrics_dict = await self._fetch_from_lrclib(title, artist, album, duration_ms)

        # 4. Fallback to YTMusic (only if LRCLIB failed and we don't even have plain lyrics)
        if (not lyrics_dict or (not lyrics_dict.get("synced") and not lyrics_dict.get("plain"))) and not best_so_far:
            logger.info(f"LRCLIB failed and no cache, falling back to YTMusic: {title} - {artist}")
            lyrics_dict = await self._fetch_from_ytmusic(track_id, title, artist)

        if lyrics_dict and (lyrics_dict.get("synced") or lyrics_dict.get("plain")):
            # Check if this is an upgrade (found synced lyrics when we only had plain)
            is_upgrade = lyrics_dict.get("synced") and not (best_so_far and best_so_far.get("synced"))

            if is_upgrade or not best_so_far:
                logger.info(f"Lyrics successfully crawled (Upgrade: {is_upgrade}) for {track_id}")
                # Cache it (both locally and on 3rah API)
                await self._cache_lyrics(track_id, lyrics_dict, title, artist)
            return lyrics_dict

        if best_so_far:
            logger.info(f"LRCLIB/YTMusic failed, returning best available cached lyrics for {track_id}")
            return best_so_far

        # Final fallback: Mark as Not exists
        logger.warning(f"No lyrics found for {track_id} from any source. Marking as Instrumental/Not exists.")
        lyrics_dict = {"synced": "Instrumental/Not exists", "plain": "Instrumental/Not exists"}
        # Cache the "not found" state
        await self._cache_lyrics(track_id, lyrics_dict, title, artist)

        return lyrics_dict

    async def _get_cached_lyrics(self, track_id):
        async with aiosqlite.connect(self.db_path) as db:
            async with db.execute("SELECT synced_lyrics, plain_lyrics FROM lyrics WHERE track_id = ?", (str(track_id),)) as cursor:
                row = await cursor.fetchone()
                return {"synced": row[0], "plain": row[1]} if row else None

    async def _cache_lyrics(self, track_id, lyrics_dict, title, artist, push_to_central=True):
        async with aiosqlite.connect(self.db_path) as db:
            await db.execute(
                "INSERT OR REPLACE INTO lyrics (track_id, synced_lyrics, plain_lyrics, title, artist) VALUES (?, ?, ?, ?, ?)",
                (str(track_id), lyrics_dict.get("synced"), lyrics_dict.get("plain"), title, artist)
            )
            await db.commit()

        if push_to_central:
            try:
                logger.info(f"Pushing lyrics for {track_id} to 3rah central API")
                result = await set_3rah_lyrics(track_id, lyrics_dict)
                if result and result.get("success"):
                    logger.info(f"Successfully synced lyrics for {track_id} to 3rah API")
                else:
                    logger.warning(f"Failed to sync lyrics for {track_id} to 3rah API: {result}")
            except Exception as e:
                logger.error(f"Error pushing lyrics to 3rah API: {e}")

    async def _fetch_from_lrclib(self, title, artist, album=None, duration_ms=0):
        try:
            from core.http_client import HttpClient
            session = await HttpClient.get_session()
            headers = {"User-Agent": "MusicMan-Crawler/1.1 (https://3rah.ir)"}

            # 1. Try exact match with duration (best for accuracy)
            params = {
                "track_name": title,
                "artist_name": artist,
            }
            if album: params["album_name"] = album
            if duration_ms: params["duration"] = int(duration_ms / 1000)

            url = "https://lrclib.net/api/get"
            max_retries = 2
            for attempt in range(max_retries):
                try:
                    async with session.get(url, params=params, headers=headers, timeout=10) as resp:
                        if resp.status == 200:
                            data = await resp.json()
                            if data.get("instrumental"):
                                return {"synced": "Instrumental", "plain": "Instrumental"}
                            return {"synced": data.get("syncedLyrics"), "plain": data.get("plainLyrics")}
                    break
                except Exception as e:
                    logger.debug(f"LRCLIB api/get attempt {attempt + 1} failed: {e}")
                    if attempt < max_retries - 1: await asyncio.sleep(1)

            # 2. Try search if direct get fails or errors
            search_url = "https://lrclib.net/api/search"
            # Try searching with explicit fields first for better results
            search_params = {"track_name": title, "artist_name": artist}
            if album: search_params["album_name"] = album

            results = []
            for attempt in range(max_retries):
                try:
                    async with session.get(search_url, params=search_params, headers=headers, timeout=10) as s_resp:
                        if s_resp.status == 200:
                            results = await s_resp.json()
                            if results: break # Found something

                        # Fallback 1: Try without album if album was provided and no results
                        if not results and album:
                            logger.info(f"LRCLIB search with album failed, trying without album: {title} - {artist}")
                            no_album_params = {"track_name": title, "artist_name": artist}
                            async with session.get(search_url, params=no_album_params, headers=headers, timeout=10) as na_resp:
                                if na_resp.status == 200:
                                    results = await na_resp.json()
                                    if results: break

                        # Fallback 2: General q search
                        if not results and s_resp.status != 404:
                            general_params = {"q": f"{title} {artist}"}
                            async with session.get(search_url, params=general_params, headers=headers, timeout=10) as g_resp:
                                if g_resp.status == 200:
                                    results = await g_resp.json()
                    break
                except Exception as e:
                    logger.debug(f"LRCLIB search attempt {attempt + 1} failed: {e}")
                    if attempt < max_retries - 1: await asyncio.sleep(1)

            if results:
                    best_score = -1

                    target_sec = duration_ms / 1000 if duration_ms else 0

                    for res in results:
                        score = 0
                        if res.get("syncedLyrics"): score += 10
                        if res.get("plainLyrics"): score += 5

                        # Duration matching
                        res_dur = res.get("duration", 0)
                        if target_sec and res_dur:
                            diff = abs(target_sec - res_dur)
                            if diff < 2: score += 10
                            elif diff < 5: score += 5
                            elif diff > 10: score -= 5

                        if score > best_score:
                            best_score = score
                            best_match = res
                            if score >= 20: break # Good enough match

                    if best_match:
                        if best_match.get("instrumental"):
                            return {"synced": "Instrumental", "plain": "Instrumental"}
                        return {"synced": best_match.get("syncedLyrics"), "plain": best_match.get("plainLyrics")}
            return None
        except Exception as e:
            logger.error(f"Error fetching lyrics from LRCLIB: {type(e).__name__}: {e}")
            return None

    async def _fetch_from_ytmusic(self, track_id, title, artist):
        try:
            track_id = str(track_id)
            video_id = None
            if track_id.startswith("yt_"):
                video_id = track_id[3:]
            else:
                # Search for the track on YouTube
                video_id = await search_youtube_track(title, artist, "", "")

            if not video_id:
                logger.warning(f"Could not find YouTube video for {title} - {artist}")
                return None

            loop = asyncio.get_event_loop()

            # Get watch playlist to find lyrics browse ID
            watch_playlist = await loop.run_in_executor(
                self._executor,
                lambda: self.ytm.get_watch_playlist(video_id)
            )

            lyrics_browse_id = watch_playlist.get('lyrics')
            if not lyrics_browse_id:
                logger.info(f"No lyrics found for {title} - {artist} (Video ID: {video_id})")
                return None

            # Fetch the actual lyrics
            lyrics_data = await loop.run_in_executor(
                self._executor,
                lambda: self.ytm.get_lyrics(lyrics_browse_id)
            )

            return {"synced": None, "plain": lyrics_data.get('lyrics')}

        except Exception as e:
            logger.error(f"Error fetching lyrics from YTMusic: {e}")
            return None

lyrics_service = LyricsService()
