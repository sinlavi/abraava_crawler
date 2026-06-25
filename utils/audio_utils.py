import os
import subprocess
from pathlib import Path
from core.logger import logger

def convert_bitrate(input_path: Path, output_path: Path, bitrate: str = "192"):
    """
    Convert an audio file to a specific bitrate using static_ffmpeg.
    """
    try:
        # Use static_ffmpeg to ensure ffmpeg is available
        # ffmpeg -i input.mp3 -ab 192k output.mp3
        cmd = [
            "static_ffmpeg",
            "-i", str(input_path),
            "-ab", f"{bitrate}k",
            "-map_metadata", "0", # Preserve basic metadata during conversion
            "-y", # Overwrite output if exists
            str(output_path)
        ]

        logger.info(f"Converting {input_path} to {bitrate}kbps...")
        result = subprocess.run(cmd, capture_output=True, text=True)

        if result.returncode != 0:
            logger.error(f"FFmpeg conversion failed: {result.stderr}")
            return False

        logger.info(f"Successfully converted to {output_path}")
        return True
    except Exception as e:
        logger.error(f"Error during audio conversion: {e}")
        return False
