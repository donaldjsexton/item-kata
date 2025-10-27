const call = async (payload) => {
  const r = await fetch("/", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    credentials: "include",
    body: JSON.stringify(payload),
  });
  if (!r.ok) throw new Error(`HTTP ${r.status}`);
  return r.json();
};

export const api = {
  list: (params = {}) => call({ action: "list", ...params }),
  create: (csrf, title) => call({ action: "create", csrf, title }),
  update: (csrf, id, patch) => call({ action: "update", csrf, id, ...patch }),
  del: (csrf, id) => call({ action: "delete", csrf, id }),
};
