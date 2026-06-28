import asyncio
import pytest
from crawlers.youtube import _check_deno, _check_proxy

@pytest.mark.asyncio
async def test_caching():
    print("Testing _check_deno caching...")
    res1 = _check_deno()
    res2 = _check_deno()
    assert res1 == res2
    print(f"Deno check result: {res1}")

    print("Testing _check_proxy caching...")
    res1 = _check_proxy()
    res2 = _check_proxy()
    assert res1 == res2
    print(f"Proxy check result: {res1}")

if __name__ == "__main__":
    asyncio.run(test_caching())
    print("Caching tests passed!")
