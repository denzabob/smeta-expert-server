# parser/config.py

import json
from pathlib import Path
from typing import Dict, Optional


class ConfigManager:
	"""Менеджер конфигураций поставщиков."""
    
	def __init__(self, config_dir: Optional[Path] = None):
		"""
		Инициализация менеджера конфигураций.
        
		Args:
			config_dir: Путь к директории с конфигурациями.
					   По умолчанию: parser/configs/
		"""
		if config_dir is None:
			self.config_dir = Path(__file__).parent / "configs"
		else:
			self.config_dir = config_dir
            
		self.config_dir.mkdir(exist_ok=True)
		self._configs_cache: Dict[str, dict] = {}
    
	def load_supplier_config(self, supplier_name: str) -> dict:
		"""
		Загружает конфигурацию поставщика из JSON файла.
        
		Args:
			supplier_name: Имя поставщика (например, 'skm_mebel')
            
		Returns:
			dict: Конфигурация поставщика
            
		Raises:
			FileNotFoundError: Если файл конфигурации не найден
			json.JSONDecodeError: Если файл имеет неверный формат
		"""
		# Проверяем кеш
		if supplier_name in self._configs_cache:
			return self._configs_cache[supplier_name]
        
		config_file = self.config_dir / f"{supplier_name}.json"
        
		if not config_file.exists():
			raise FileNotFoundError(
				f"Конфигурация для '{supplier_name}' не найдена: {config_file}"
			)
        
		with open(config_file, 'r', encoding='utf-8') as f:
			config = json.load(f)
        
		# Валидация обязательных полей
		self._validate_config(config, supplier_name)
        
		# Кешируем
		self._configs_cache[supplier_name] = config
        
		return config
    
	def reload_config(self, supplier_name: str) -> dict:
		"""
		Перезагружает конфигурацию поставщика (сбрасывает кеш).
        
		Args:
			supplier_name: Имя поставщика
            
		Returns:
			dict: Обновленная конфигурация
		"""
		if supplier_name in self._configs_cache:
			del self._configs_cache[supplier_name]
        
		return self.load_supplier_config(supplier_name)
    
	def list_suppliers(self) -> list[str]:
		"""
		Возвращает список всех доступных поставщиков.
        
		Returns:
			list[str]: Список имен поставщиков
		"""
		config_files = self.config_dir.glob("*.json")
		return [f.stem for f in config_files]
    
	def _validate_config(self, config: dict, supplier_name: str):
		"""
		Валидирует конфигурацию поставщика.
        
		Args:
			config: Конфигурация для проверки
			supplier_name: Имя поставщика (для сообщений об ошибках)
            
		Raises:
			ValueError: Если конфигурация невалидна
		"""
		required_fields = ['name', 'base_url', 'adapter_class']
        
		for field in required_fields:
			if field not in config:
				raise ValueError(
					f"Конфигурация '{supplier_name}' не содержит обязательное поле: {field}"
				)
    
	def save_supplier_config(self, supplier_name: str, config: dict):
		"""
		Сохраняет конфигурацию поставщика в JSON файл.
        
		Args:
			supplier_name: Имя поставщика
			config: Конфигурация для сохранения
		"""
		self._validate_config(config, supplier_name)
        
		config_file = self.config_dir / f"{supplier_name}.json"
        
		with open(config_file, 'w', encoding='utf-8') as f:
			json.dump(config, f, ensure_ascii=False, indent=2)
        
		# Обновляем кеш
		self._configs_cache[supplier_name] = config


# Глобальный экземпляр менеджера конфигураций
config_manager = ConfigManager()
