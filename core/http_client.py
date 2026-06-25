import aiohttp
import asyncio
import random
from typing import Optional, Tuple, Any, List
from aiohttp_socks import ProxyConnector

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

class HttpClient:
    _session: Optional[aiohttp.ClientSession] = None

    @classmethod
    async def get_session(cls) -> aiohttp.ClientSession:
        if cls._session is None or cls._session.closed:
            from core.config import PROXY
            if PROXY and PROXY.startswith("socks"):
                # python-socks does not support socks5h scheme, normalize to socks5
                proxy_url = PROXY.replace("socks5h://", "socks5://")
                connector = ProxyConnector.from_url(proxy_url, ssl=False)
            else:
                connector = aiohttp.TCPConnector(ssl=False)
            cls._session = aiohttp.ClientSession(connector=connector, trust_env=True)
        return cls._session

    @classmethod
    async def close(cls):
        if cls._session and not cls._session.closed:
            await cls._session.close()

    @classmethod
    async def request_with_methods(cls, method: str, url: str, **kwargs) -> Tuple[Any, int, bool]:
        """
        Executes a request using multiple methods (different User-Agents).
        Returns (data, status_code, is_technical_error).
        is_technical_error is True if ALL methods failed with something other than 404.
        """
        session = await cls.get_session()
        from core.config import PROXY
        current_proxy = PROXY if PROXY and not PROXY.startswith("socks") else None

        headers = kwargs.pop('headers', {}).copy()

        last_status = 0

        # Randomize UA order to be less predictable
        uas = list(USER_AGENTS)
        random.shuffle(uas)

        for i, ua in enumerate(uas):
            headers['User-Agent'] = ua
            try:
                async with session.request(method, url, headers=headers, proxy=current_proxy, ssl=False, timeout=15, **kwargs) as resp:
                    last_status = resp.status
                    if resp.status == 200:
                        try:
                            data = await resp.json()
                        except:
                            data = await resp.read()
                        return data, resp.status, False

                    if resp.status == 404:
                        # Confirmed not found
                        return None, 404, False

                    # Log failure for this method
                    # logger would be good here but it might cause circular import if not careful
                    # from core.logger import logger
                    # logger.debug(f"Method {i+1} failed with status {resp.status} for {url}")

            except Exception as e:
                # Connection error etc is a technical error for this attempt
                pass

            # Short sleep between retries
            await asyncio.sleep(0.3)

        # If all 8 failed and none was 404
        return None, last_status, True
