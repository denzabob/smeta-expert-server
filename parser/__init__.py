# parser/__init__.py

"""
Модульная система парсинга материалов.

Поддерживает подключение неограниченного числа поставщиков
через индивидуальные адаптеры.
"""

from .base_adapter import SupplierAdapter, MaterialData, ParseResult
from .config import config_manager
from .core import ParserCore

__all__ = [
    'SupplierAdapter',
    'MaterialData',
    'ParseResult',
    'config_manager',
    'ParserCore',
]
