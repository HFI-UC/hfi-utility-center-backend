import assert from "node:assert/strict";
import { test } from "node:test";
import worker, { consumeBatch, pullAndPublish, TaskDispatcher } from "./index.js";

function task(id = 42) {
  return { taskId: id, kind: "reservation_created", dispatchToken: "lease-123" };
}

function message(body, attempts = 1) {
  const calls = [];
  return {
    body,
    attempts,
    calls,
    ack() { calls.push({ action: "ack" }); },
    retry(options) { calls.push({ action: "retry", ...options }); },
  };
}

test("compensation publishes only leased tasks from PHP", async () => {
  const sent = [];
  const env = {
    PHP_API_BASE_URL: "https://example.test",
    TASK_PULL_SECRET: "pull-secret",
    TASK_QUEUE: { async sendBatch(messages) { sent.push(...messages); } },
  };
  const count = await pullAndPublish(env, async (url, options) => {
    assert.equal(url.toString(), "https://example.test/tasks?limit=50");
    assert.equal(options.headers.authorization, "Bearer pull-secret");
    return Response.json({ success: true, data: { tasks: [task()] } });
  });
  assert.equal(count, 1);
  assert.deepEqual(sent, [{ body: task() }]);
});

test("invalid PHP response never publishes a task", async () => {
  let published = false;
  const env = {
    PHP_API_BASE_URL: "https://example.test",
    TASK_PULL_SECRET: "pull-secret",
    TASK_QUEUE: { async sendBatch() { published = true; } },
  };
  await assert.rejects(
    pullAndPublish(env, async () => Response.json({ success: true, data: { tasks: [{ taskId: 1 }] } })),
    /invalid response/,
  );
  await assert.rejects(
    pullAndPublish(env, async () => Response.json({ tasks: [task()] })),
    /invalid response/,
  );
  assert.equal(published, false);
});

test("Queue consumer acknowledges success and retries a transient PHP failure", async () => {
  const ok = message(task());
  const retry = message(task(43), 3);
  const env = { PHP_API_BASE_URL: "https://example.test", TASK_EXECUTE_SECRET: "execute-secret" };
  await consumeBatch({ messages: [ok, retry] }, env, async (url, options) => {
    assert.equal(options.headers.authorization, "Bearer execute-secret");
    assert.deepEqual(JSON.parse(options.body), { dispatchToken: "lease-123" });
    return new Response(null, { status: url.endsWith("/42/execute") ? 200 : 503 });
  });
  assert.deepEqual(ok.calls, [{ action: "ack" }]);
  assert.deepEqual(retry.calls, [{ action: "retry", delaySeconds: 8 }]);
});

test("Queue consumer acknowledges invalid tasks and retries contention", async () => {
  const invalid = message(task(44));
  const contention = message(task(45), 2);
  const env = { PHP_API_BASE_URL: "https://example.test", TASK_EXECUTE_SECRET: "execute-secret" };
  const originalError = console.error;
  console.error = () => {};
  try {
    await consumeBatch({ messages: [invalid, contention] }, env, async (url) => (
      new Response(null, { status: url.endsWith("/44/execute") ? 422 : 409 })
    ));
  } finally {
    console.error = originalError;
  }
  assert.deepEqual(invalid.calls, [{ action: "ack" }]);
  assert.deepEqual(contention.calls, [{ action: "retry", delaySeconds: 4 }]);
});

test("legacy Queue messages remain processable during migration", async () => {
  const old = message({ jobId: 9 });
  const env = {
    PHP_PROCESS_URL: "https://example.test/internal/outbox/process",
    QUEUE_PROCESS_SECRET: "legacy-secret",
  };
  await consumeBatch({ messages: [old] }, env, async (url, options) => {
    assert.equal(url, env.PHP_PROCESS_URL);
    assert.deepEqual(JSON.parse(options.body), { jobId: 9 });
    return new Response(null, { status: 200 });
  });
  assert.deepEqual(old.calls, [{ action: "ack" }]);
});

test("Durable Object starts and reschedules its 30 second alarm", async () => {
  let alarm = null;
  const state = { storage: {
    async getAlarm() { return alarm; },
    async setAlarm(timestamp) { alarm = timestamp; },
  } };
  const dispatcher = new TaskDispatcher(state, {
    DISPATCH_ENABLED: "true",
    PHP_API_BASE_URL: "https://example.test",
    TASK_PULL_SECRET: "pull-secret",
    TASK_QUEUE: { async sendBatch() {} },
  });
  const startedAt = Date.now();
  const response = await dispatcher.fetch(new Request("https://dispatcher.internal/start", { method: "POST" }));
  assert.equal(response.status, 204);
  assert.ok(alarm >= startedAt + 30_000);
  const initialAlarm = alarm;
  await dispatcher.fetch(new Request("https://dispatcher.internal/start", { method: "POST" }));
  assert.equal(alarm, initialAlarm);

  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => Response.json({ success: true, data: { tasks: [] } });
  try {
    await dispatcher.alarm();
  } finally {
    globalThis.fetch = originalFetch;
  }
  assert.ok(alarm >= initialAlarm);
});

test("failed pull does not stop subsequent Durable Object alarms", async () => {
  let nextAlarm = null;
  const state = { storage: {
    async setAlarm(timestamp) { nextAlarm = timestamp; },
  } };
  const dispatcher = new TaskDispatcher(state, {
    DISPATCH_ENABLED: "true",
    PHP_API_BASE_URL: "https://example.test",
    TASK_PULL_SECRET: "pull-secret",
    TASK_QUEUE: { async sendBatch() {} },
  });
  const originalFetch = globalThis.fetch;
  const originalError = console.error;
  globalThis.fetch = async () => { throw new Error("temporary outage"); };
  console.error = () => {};
  try {
    await dispatcher.alarm();
  } finally {
    globalThis.fetch = originalFetch;
    console.error = originalError;
  }
  assert.ok(nextAlarm >= Date.now() + 29_000);
});

test("disabled dispatcher neither starts nor pulls tasks", async () => {
  const env = {
    DISPATCH_ENABLED: "false",
    PHP_API_BASE_URL: "https://example.test",
    TASK_DISPATCHER: { idFromName() { throw new Error("should not be called"); } },
  };
  await worker.scheduled({}, env);
  await worker.scheduled({}, { ...env, DISPATCH_ENABLED: "true", PHP_API_BASE_URL: "" });

  let scheduled = false;
  const dispatcher = new TaskDispatcher({ storage: {
    async setAlarm() { scheduled = true; },
  } }, env);
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async () => { throw new Error("should not be called"); };
  try {
    await dispatcher.alarm();
  } finally {
    globalThis.fetch = originalFetch;
  }
  assert.equal(scheduled, false);
});
