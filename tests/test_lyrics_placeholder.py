import asyncio
import unittest
from unittest.mock import MagicMock, patch
import sys

# Mock YTMusic before importing LyricsService to avoid ConnectionError in __init__
sys.modules['ytmusicapi'] = MagicMock()

from services.lyrics_service import LyricsService

class TestLyricsPlaceholder(unittest.IsolatedAsyncioTestCase):
    @patch('services.lyrics_service.LyricsService._fetch_from_lrclib')
    @patch('services.lyrics_service.LyricsService._fetch_from_ytmusic')
    @patch('crawlers.itunes.fetch_itunes')
    async def test_lyrics_placeholder(self, mock_fetch_itunes, mock_ytm, mock_lrclib):
        # 3rah returns success: False
        mock_fetch_itunes.return_value = {"success": False}
        # LRCLIB returns empty dict
        mock_lrclib.return_value = {}
        # YTMusic returns empty dict
        mock_ytm.return_value = {}

        with patch('services.lyrics_service.LyricsService._sync_to_central') as mock_sync:
            ls = LyricsService()
            lyrics = await ls.get_lyrics("test_id", "test_title", "test_artist")

            self.assertEqual(lyrics, {"synced": None, "plain": "Instrumental/Not found"})
            mock_sync.assert_called_once()

if __name__ == '__main__':
    unittest.main()
