export default {
  async queue(batch, env) {
    for (const message of batch.messages) {
      const jobId = message.body && message.body.jobId;
      if (!jobId) {
        message.ack();
        continue;
      }
      let response;
      try {
        response = await fetch(env.PHP_PROCESS_URL, {
          method: "POST",
          headers: {
            "content-type": "application/json",
            authorization: `Bearer ${env.QUEUE_PROCESS_SECRET}`,
          },
          body: JSON.stringify({ jobId }),
        });
      } catch {
        message.retry({ delaySeconds: retryDelay(message.attempts) });
        continue;
      }
      if (response.ok) {
        message.ack();
        continue;
      }
      message.retry({ delaySeconds: retryDelay(message.attempts) });
    }
  },
};

function retryDelay(attempts) {
  const exponent = Math.min(Number(attempts) || 1, 8);
  return Math.min(Math.max(2 ** exponent, 2), 300);
}
