import sys
from suppliers.skm_mebel import extract_material_data
import json
import requests

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python main.py <url>")
        sys.exit(1)

    url = sys.argv[1]
    data = extract_material_data(url)

# Отправка в Laravel
BASE_URL = "http://127.0.0.1:8000"
API_URL = f"{BASE_URL}/api/parser/materials"
# Отправка в Laravel через Docker-сеть
response = requests.post(
    "http://host.docker.internal:8000/api/parser/materials",  # при запуске вручную
    # ИЛИ (если парсер тоже в compose):
    # "http://web/api/parser/materials",
    json=data,
    headers={"Content-Type": "application/json", "Accept": "application/json"}
)

if response.status_code in (200, 201):
    print("✅ Успешно сохранено в БД")
else:
    print("❌ Ошибка:", response.text)

    print("✅ Parsed data:")
    for k, v in data.items():
        print(f"  {k}: {v}")