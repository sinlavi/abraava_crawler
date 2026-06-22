from core.config import FOOTER
import logging
import asyncio
from telegram import Bot, Message
from telegram.error import BadRequest, RetryAfter

logger = logging.getLogger("ABRAAVA:MESSAGES")

async def safe_delete(message, attempt=1):
    if not message: return
    try:
        await message.delete()
    except Exception as e:
        err = str(e).lower()
        if "message not found" in err or "message to delete not found" in err: return
        if "retry after" in err and attempt < 3:
            import re
            seconds = re.search(r'in (\d+) seconds', err)
            sleep_time = int(seconds.group(1)) if seconds else 0.5 * attempt
            await asyncio.sleep(sleep_time)
            return await safe_delete(message, attempt + 1)
        logger.debug(f"Safe delete failed: {e}")

async def send_message(bot: Bot, chat_id, text, reply_markup=None, reply_to_message_id=None, **kwargs):
    try:
        await bot.send_chat_action(chat_id, "typing")
        return await bot.send_message(chat_id, text=f"{text}{FOOTER}", reply_to_message_id=reply_to_message_id)
    except RetryAfter as e:
        await asyncio.sleep(e.retry_after)
        return await bot.send_message(chat_id, text=f"{text}{FOOTER}", reply_to_message_id=reply_to_message_id)
    except Exception as e:
        logger.error(f"Send message failed: {e}")
        raise

async def edit_message(message: Message, text, reply_markup=None, attempt=1, **kwargs):
    if not message: return None
    chat_id = message.chat.id

    try:
        return await message.edit_text(text=f"{text}{FOOTER}")
    except BadRequest as e:
        err_msg = str(e).lower()
        if "message is not modified" in err_msg:
            return message
        if "message not found" in err_msg or "message to edit not found" in err_msg:
            return await send_message(message.bot, chat_id, text)
        raise
    except RetryAfter as e:
        if attempt < 3:
            await asyncio.sleep(e.retry_after)
            return await edit_message(message, text, attempt=attempt + 1)
        raise
    except Exception as e:
        logger.warning(f"Failed to edit (attempt {attempt}), sending new: {e}")
        try:
            await safe_delete(message)
        except Exception:
            pass
        return await send_message(message.bot, chat_id, text)

async def reply_message(message: Message, text: str, **kwargs):
    await message.bot.send_chat_action(message.chat.id, "typing")
    return await message.reply_text(text=f"{text}{FOOTER}")
