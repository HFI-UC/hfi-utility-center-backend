import dotenv
import os
import json

dotenv.load_dotenv()

database_url = os.getenv("DATABASE_URL") or ""
smtp_server = os.getenv("SMTP_SERVER") or ""
smtp_email = os.getenv("SMTP_EMAIL") or ""
smtp_password = os.getenv("SMTP_PASSWORD") or ""
base_url = os.getenv("BASE_URL") or ""
cloudflare_secret = os.getenv("CLOUDFLARE_SECRET") or ""
port = int(os.getenv("PORT") or 8000)
debug = os.getenv("DEBUG", "false").lower() == "true"
daily_report_recipients: list[str] = json.loads(
    os.getenv("DAILY_REPORT_RECIPIENTS") or "[]"
)
frontend_url = os.getenv("FRONTEND_URL") or ""
