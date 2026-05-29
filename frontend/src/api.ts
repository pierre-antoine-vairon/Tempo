const API_URL = import.meta.env.VITE_API_URL as string;

export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${API_URL}${path}`);

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`${res.status} ${res.statusText} - ${text}`);
  }

  return res.json() as Promise<T>;
}

export async function apiPut<TResponse, TBody>(
  path: string,
  body: TBody,
): Promise<TResponse> {
  const res = await fetch(`${API_URL}${path}`, {
    method: "PUT",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(body),
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`${res.status} ${res.statusText} - ${text}`);
  }

  return res.json() as Promise<TResponse>;
}
