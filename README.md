# HFI Utility Center Backend

Written in [Python](https://www.python.org/) + [FastAPI](https://fastapi.tiangolo.com/) + [SQLAlchemy](https://www.sqlalchemy.org/) ([SQLModel](https://sqlmodel.tiangolo.com/)).

## Install

1. Install [Python](https://www.python.org/) + [Poetry](https://python-poetry.org/).

    Example: install Poetry using [pipx](https://pipx.pypa.io/):

    ```sh
    pip install --user pipx
    ```

    ```sh
    pipx install poetry
    pipx ensurepath
    ```

2. Install dependencies.

    ```sh
    poetry install
    ```

## Configure

Example configuration file:

```ini
# Database URL, reference: https://docs.sqlalchemy.org/en/20/core/engines.html#database-urls
DATABASE_URL='sqlite://./test.db'
# SMTP config
SMTP_SERVER=smtp.example.com
SMTP_EMAIL=no-reply@example.com
SMTP_PASSWORD=s3cr3tp4ssw0rd
# Base URL (your frontend URL)
BASE_URL=https://example.com
# Port
PORT=8000
# Debug mode
DEBUG=false
# E-mails that will receive daily reservation reports (in JSON format)
DAILY_REPORT_RECIPIENTS='["admin@example.com"]'
# Cloudflare Turnstile secret
CLOUDFLARE_SECRET='<your-cloudflare-secret-here>'
```

## Run

Run the backend.

```sh
poetry run python app.py
```

## Test

Test the backend using [pytest](https://docs.pytest.org/):

```sh
poetry run pytest
```