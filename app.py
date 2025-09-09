from core import app
from core.env import *
import uvicorn

app = app

if __name__ == "__main__":
    uvicorn.run("core:app", host="0.0.0.0", port=port, reload=True, access_log=False, log_level="debug" if debug else "info")
