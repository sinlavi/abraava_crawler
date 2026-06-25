import asyncio
import logging
from crawlers.youtube import _calculate_relevance_score

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("TestRelevance")

def test_relevance_scoring():
    # Test case 1: Perfect match
    video_info = {
        'title': 'The Less I Know The Better',
        'channel': 'Tame Impala',
        'uploader': 'Tame Impala',
        'upload_date': '20150717',
        'duration': 218 # 3:38
    }
    score = _calculate_relevance_score(
        video_info,
        'The Less I Know The Better',
        'Tame Impala',
        'Currents',
        '2015',
        target_duration_ms=218000
    )
    logger.info(f"Perfect match score: {score}")
    assert score >= 0.9

    # Test case 2: Duration mismatch
    video_info_bad_dur = video_info.copy()
    video_info_bad_dur['duration'] = 300 # 5:00
    score_bad_dur = _calculate_relevance_score(
        video_info_bad_dur,
        'The Less I Know The Better',
        'Tame Impala',
        'Currents',
        '2015',
        target_duration_ms=218000
    )
    logger.info(f"Bad duration match score: {score_bad_dur}")
    assert score_bad_dur < score

    # Test case 3: Title mismatch
    video_info_bad_title = video_info.copy()
    video_info_bad_title['title'] = 'Let It Happen'
    score_bad_title = _calculate_relevance_score(
        video_info_bad_title,
        'The Less I Know The Better',
        'Tame Impala',
        'Currents',
        '2015',
        target_duration_ms=218000
    )
    logger.info(f"Bad title match score: {score_bad_title}")
    assert score_bad_title < score

if __name__ == "__main__":
    test_relevance_scoring()
    print("Relevance scoring tests passed!")
