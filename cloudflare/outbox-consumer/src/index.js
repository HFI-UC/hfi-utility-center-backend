const DISPATCH_INTERVAL_MS = 30_000;
const PULL_LIMIT = 50;

function taskApiUrl(base, path) {
  if (!base) {
    throw new Error("PHP_API_BASE_URL is not configured");
  }
  return new URL(path, `${base.replace(/\/$/, "")}/`).toString();
}

export function isTaskMessage(value) {
  return value !== null
    && typeof value === "object"
    && Number.isSafeInteger(value.taskId)
    && value.taskId > 0
    && typeof value.kind === "string"
    && value.kind.length > 0
    && typeof value.dispatchToken === "string"
    && value.dispatchToken.length > 0
    && value.dispatchToken.length <= 256;
}

export function retryDelay(attempts) {
  const exponent = Math.min(Math.max(Number(attempts) || 1, 1), 8);
  return Math.min(2 ** exponent, 300);
}

export async function pullAndPublish(env, fetcher = fetch) {
  if (!env.TASK_PULL_SECRET) {
    throw new Error("TASK_PULL_SECRET is not configured");
  }
  const url = new URL(taskApiUrl(env.PHP_API_BASE_URL, "/tasks"));
  url.searchParams.set("limit", String(PULL_LIMIT));
  const response = await fetcher(url, {
    method: "GET",
    headers: { authorization: `Bearer ${env.TASK_PULL_SECRET}` },
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok) {
    throw new Error(`Task pull returned HTTP ${response.status}`);
  }
  const result = await response.json();
  const tasks = result?.success === true ? result?.data?.tasks : undefined;
  if (!Array.isArray(tasks) || tasks.length > PULL_LIMIT
    || !tasks.every(isTaskMessage)) {
    throw new Error("Task pull returned an invalid response");
  }
  if (tasks.length > 0) {
    await env.TASK_QUEUE.sendBatch(tasks.map((task) => ({ body: task })));
  }
  return tasks.length;
}

export class TaskDispatcher {
  constructor(state, env) {
    this.state = state;
    this.env = env;
  }

  async fetch(request) {
    if (request.method !== "POST" || new URL(request.url).pathname !== "/start") {
      return new Response(null, { status: 404 });
    }
    if (this.env.DISPATCH_ENABLED !== "true" || !this.env.PHP_API_BASE_URL) {
      return new Response(null, { status: 204 });
    }
    if (await this.state.storage.getAlarm() === null) {
      await this.state.storage.setAlarm(Date.now() + DISPATCH_INTERVAL_MS);
    }
    return new Response(null, { status: 204 });
  }

  async alarm() {
    if (this.env.DISPATCH_ENABLED !== "true" || !this.env.PHP_API_BASE_URL) {
      return;
    }
    try {
      const count = await pullAndPublish(this.env);
      if (count > 0) {
        console.log(JSON.stringify({ event: "task_dispatch", count }));
      }
    } catch (error) {
      console.error(JSON.stringify({
        event: "task_dispatch_failed",
        error: error instanceof Error ? error.message : String(error),
      }));
    } finally {
      await this.state.storage.setAlarm(Date.now() + DISPATCH_INTERVAL_MS);
    }
  }
}

export async function consumeBatch(batch, env, fetcher = fetch) {
  for (const message of batch.messages) {
    const task = message.body;
    let url;
    let body;
    let secret;
    let taskId;
    if (isTaskMessage(task)) {
      taskId = task.taskId;
      url = taskApiUrl(env.PHP_API_BASE_URL, `/tasks/${task.taskId}/execute`);
      body = { dispatchToken: task.dispatchToken };
      secret = env.TASK_EXECUTE_SECRET;
    } else if (task && Number.isSafeInteger(task.jobId) && task.jobId > 0) {
      // Messages published before the PHP task API is deployed may still be in uc.
      taskId = task.jobId;
      url = env.PHP_PROCESS_URL || taskApiUrl(env.PHP_API_BASE_URL, "/internal/outbox/process");
      body = { jobId: task.jobId };
      secret = env.QUEUE_PROCESS_SECRET;
    } else {
      console.error(JSON.stringify({ event: "task_message_invalid" }));
      message.ack();
      continue;
    }

    if (!secret) {
      console.error(JSON.stringify({ event: "task_execute_secret_missing", taskId }));
      message.retry({ delaySeconds: retryDelay(message.attempts) });
      continue;
    }

    try {
      const response = await fetcher(url, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          authorization: `Bearer ${secret}`,
        },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(20_000),
      });
      if (response.ok) {
        message.ack();
      } else if (response.status === 400 || response.status === 404 || response.status === 422) {
        console.error(JSON.stringify({ event: "task_execute_invalid", taskId, status: response.status }));
        message.ack();
      } else {
        console.error(JSON.stringify({ event: "task_execute_failed", taskId, status: response.status }));
        message.retry({ delaySeconds: retryDelay(message.attempts) });
      }
    } catch (error) {
      console.error(JSON.stringify({
        event: "task_execute_network_error",
        taskId,
        error: error instanceof Error ? error.name : String(error),
      }));
      message.retry({ delaySeconds: retryDelay(message.attempts) });
    }
  }
}

export default {
  async scheduled(_event, env) {
    if (env.DISPATCH_ENABLED !== "true" || !env.PHP_API_BASE_URL) {
      return;
    }
    const id = env.TASK_DISPATCHER.idFromName("outbox");
    const stub = env.TASK_DISPATCHER.get(id);
    const response = await stub.fetch("https://dispatcher.internal/start", { method: "POST" });
    if (!response.ok) {
      throw new Error(`Task dispatcher initialization returned HTTP ${response.status}`);
    }
  },
  async queue(batch, env) {
    await consumeBatch(batch, env);
  },
};
