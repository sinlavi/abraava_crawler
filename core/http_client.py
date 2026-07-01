import aiohttp
import asyncio
import random
import logging
from typing import Optional, Tuple, Any, List
from aiohttp_socks import ProxyConnector
from core.config import USER_AGENTS

logger = logging.getLogger("HttpClient")

class HttpClient:
    _proxy_session: Optional[aiohttp.ClientSession] = None
    _direct_session: Optional[aiohttp.ClientSession] = None

    @classmethod
    async def get_session(cls, use_proxy: bool = True) -> aiohttp.ClientSession:
        from core.config import PROXY
        if use_proxy and PROXY:
            if cls._proxy_session is None or cls._proxy_session.closed:
                if PROXY.startswith("socks"):
                    proxy_url = PROXY.replace("socks5h://", "socks5://")
                    connector = ProxyConnector.from_url(proxy_url, ssl=False)
                else:
                    connector = aiohttp.TCPConnector(ssl=False)
                cls._proxy_session = aiohttp.ClientSession(connector=connector, trust_env=True)
            return cls._proxy_session
        else:
            if cls._direct_session is None or cls._direct_session.closed:
                connector = aiohttp.TCPConnector(ssl=False)
                cls._direct_session = aiohttp.ClientSession(connector=connector, trust_env=True)
            return cls._direct_session

    @classmethod
    async def close(cls):
        if cls._proxy_session and not cls._proxy_session.closed:
            await cls._proxy_session.close()
        if cls._direct_session and not cls._direct_session.closed:
            await cls._direct_session.close()

    @classmethod
    async def request_with_methods(cls, method: str, url: str, **kwargs) -> Tuple[Any, int, bool]:
        """
        Executes a request using multiple methods (different User-Agents and toggling proxy).
        Returns (data, status_code, is_technical_error).
        """
        from core.config import PROXY
        headers = kwargs.pop('headers', {}).copy()
        last_status = 0

        uas = list(USER_AGENTS)
        random.shuffle(uas)

        for i, ua in enumerate(uas):
            headers['User-Agent'] = ua
            # Toggle proxy: even attempts use proxy (if available), odd attempts direct
            use_proxy = (i % 2 == 0) if PROXY else False
            session = await cls.get_session(use_proxy=use_proxy)

            # For HTTP proxies in direct session, we still might want to pass it?
            # No, if use_proxy is False we want direct.
            current_proxy = PROXY if (use_proxy and PROXY and not PROXY.startswith("socks")) else None

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
                        return None, 404, False

                    if resp.status == 429:
                        # Rate limited, wait a bit longer and continue
                        await asyncio.sleep(1.0)
                        continue

                    if resp.status >= 500:
                        # Check for specific "max_user_connections" error from 3rah API
                        try:
                            error_text = await resp.text()
                            if "max_user_connections" in error_text:
                                logger.error("3rah API: max_user_connections exceeded. Waiting 5 minutes...")
                                await asyncio.sleep(300)
                        except Exception:
                            pass
                        logger.warning(f"Request to {url} failed with status {resp.status}, trying next method...")
                        continue

                    if resp.status == 403:
                        # Forbidden, try next combination immediately
                        logger.warning(f"Request to {url} failed with status {resp.status}, trying next method...")
                        continue

            except Exception as e:
                logger.debug(f"Request to {url} failed with error: {e}")
                pass

            await asyncio.sleep(0.1)

        return None, last_status, True
