import React, { useEffect, useState } from "react";
import { api } from "./api";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import {
  faPlus,
  faEdit,
  faTrash,
  faChevronLeft,
  faChevronRight,
  faSearch,
} from "@fortawesome/free-solid-svg-icons";

const pageSize = 10;

export default function App() {
  const [csrf, setCsrf] = useState("");
  const [items, setItems] = useState([]);
  const [hasMore, setHasMore] = useState(false);
  const [title, setTitle] = useState("");
  const [error, setError] = useState("");
  const [fieldErr, setFieldErr] = useState("");
  const [page, setPage] = useState(0);
  const [q, setQ] = useState("");
  const [typingQ, setTypingQ] = useState("");

  const refresh = async (p = page, query = q) => {
    try {
      setError("");
      setFieldErr("");
      const res = await api.list({
        limit: pageSize,
        offset: p * pageSize,
        q: query,
      });
      setItems(res.items || []);
      setHasMore(!!res.has_more);
      if (res.csrf) setCsrf(res.csrf);
    } catch {
      setError("Load error");
    }
  };

  useEffect(() => {
    const token = (window.__CFG__ && window.__CFG__.csrf) || "";
    setCsrf(token);
    refresh(0, "");
  }, []);

  useEffect(() => {
    const t = setTimeout(() => {
      setPage(0);
      setQ(typingQ);
      refresh(0, typingQ);
    }, 300);
    return () => clearTimeout(t);
  }, [typingQ]);

  const onSubmit = async (e) => {
    e.preventDefault();
    const t = title.trim();
    if (!t) return;
    try {
      setError("");
      setFieldErr("");
      const created = await api.create(csrf, t);
      setItems((prev) => [created, ...prev]);
      setTitle("");
    } catch (e) {
      const s = String(e);
      if (s.includes("422")) setFieldErr("Title required");
      else if (s.includes("419")) setError("CSRF Error");
      else setError("Create error");
    }
  };

  const toggle = async (id, done) => {
    try {
      setError("");
      setFieldErr("");
      const updated = await api.update(csrf, id, { done: +!done });
      setItems((prev) => prev.map((x) => (x.id === id ? updated : x)));
    } catch (e) {
      const s = String(e);
      if (s.includes("419")) setError("CSRF Error");
      else setError("Update error");
    }
  };

  const rename = async (id) => {
    const t = prompt("New title?");
    if (t == null) return;
    const s = t.trim();
    if (!s) return;
    try {
      setError("");
      setFieldErr("");
      const updated = await api.update(csrf, id, { title: s });
      setItems((prev) => prev.map((x) => (x.id === id ? updated : x)));
    } catch (e) {
      const msg = String(e);
      if (msg.includes("422")) setFieldErr("Title required");
      else setError("Update error");
    }
  };

  const remove = async (id) => {
    if (!confirm("Delete?")) return;
    try {
      setError("");
      setFieldErr("");
      await api.del(csrf, id);
      setItems((prev) => prev.filter((x) => x.id !== id));
    } catch {
      setError("Delete error");
    }
  };

  const prev = async () => {
    const p = Math.max(0, page - 1);
    setPage(p);
    await refresh(p, q);
  };

  const next = async () => {
    if (!hasMore) return;
    const p = page + 1;
    setPage(p);
    await refresh(p, q);
  };

  return (
    <div className="wrap">
      <h1>Items</h1>

      <form onSubmit={onSubmit} className="row">
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          placeholder="Add item..."
          maxLength={120}
          aria-label="Item title"
        />
        <button type="submit" title="Add">
          <FontAwesomeIcon icon={faPlus} /> Add
        </button>
      </form>

      {fieldErr && (
        <div className="field-error" role="alert">
          {fieldErr}
        </div>
      )}
      {error && (
        <div className="error" role="alert">
          {error}
        </div>
      )}

      <div className="row search">
        <FontAwesomeIcon icon={faSearch} />
        <input
          placeholder="Search..."
          value={typingQ}
          onChange={(e) => setTypingQ(e.target.value)}
          aria-label="Search"
        />
      </div>

      <ul className="list">
        {items.map((i) => (
          <li key={i.id} className={i.done ? "done" : ""}>
            <input
              type="checkbox"
              checked={!!i.done}
              onChange={() => toggle(i.id, i.done)}
              aria-label={`Mark ${i.title} done`}
            />
            <span>{i.title}</span>
            <div className="actions">
              <button onClick={() => rename(i.id)} title="Edit">
                <FontAwesomeIcon icon={faEdit} />
              </button>
              <button onClick={() => remove(i.id)} title="Delete">
                <FontAwesomeIcon icon={faTrash} />
              </button>
            </div>
          </li>
        ))}
      </ul>

      <div className="pager">
        <button onClick={prev} disabled={page === 0} title="Prev">
          <FontAwesomeIcon icon={faChevronLeft} /> Prev
        </button>
        <span className="page">{page + 1}</span>
        <button onClick={next} disabled={!hasMore} title="Next">
          Next <FontAwesomeIcon icon={faChevronRight} />
        </button>
      </div>
    </div>
  );
}
