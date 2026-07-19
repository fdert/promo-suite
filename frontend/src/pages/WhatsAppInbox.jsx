import { useEffect, useState, useCallback, useRef } from 'react';
import { Send, MessageCircle, RefreshCw } from 'lucide-react';
import { fn } from '../lib/api';
import { Card, PageLoading, EmptyState, Badge } from '../components/ui/Surfaces';
import Button from '../components/ui/Button';
import { Input } from '../components/ui/Field';
import { useToast } from '../context/ToastContext';

function timeAgo(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr.replace(' ', 'T'));
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleString('ar-SA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export default function WhatsAppInbox() {
  const [conversations, setConversations] = useState([]);
  const [loadingList, setLoadingList] = useState(true);
  const [selected, setSelected] = useState(null);
  const [messages, setMessages] = useState([]);
  const [loadingThread, setLoadingThread] = useState(false);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const bottomRef = useRef(null);
  const toast = useToast();

  const loadConversations = useCallback(async () => {
    setLoadingList(true);
    try {
      const { conversations: convs } = await fn.call('wa-conversations');
      setConversations(convs || []);
    } catch (err) {
      toast.error(err.message || 'تعذر تحميل المحادثات');
    } finally {
      setLoadingList(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    loadConversations();
  }, [loadConversations]);

  const openConversation = useCallback(
    async (conv) => {
      setSelected(conv);
      setLoadingThread(true);
      try {
        const { messages: msgs } = await fn.call('wa-thread', { phone: conv.phone, mark_read: true });
        setMessages(msgs || []);
        setTimeout(() => bottomRef.current?.scrollIntoView({ behavior: 'auto' }), 50);
      } catch (err) {
        toast.error(err.message || 'تعذر تحميل المحادثة');
      } finally {
        setLoadingThread(false);
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    []
  );

  const handleSend = async (e) => {
    e.preventDefault();
    if (!draft.trim() || !selected) return;
    setSending(true);
    try {
      await fn.call('wa-send', { to: selected.phone, message: draft.trim() });
      setDraft('');
      await openConversation(selected);
    } catch (err) {
      toast.error(err.message || 'فشل إرسال الرسالة');
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="font-display text-2xl font-extrabold text-ink">محادثات واتساب</h1>
          <p className="mt-1 text-sm text-ink-soft">تابع وراسل عملاءك عبر واتساب</p>
        </div>
        <Button variant="outline" size="sm" onClick={loadConversations}>
          <RefreshCw className="h-4 w-4" />
          تحديث
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3" style={{ height: '65vh' }}>
        <Card className="flex flex-col overflow-hidden lg:col-span-1">
          <div className="flex-1 overflow-y-auto scrollbar-thin -mx-5 -mt-5 px-2 pt-2">
            {loadingList ? (
              <PageLoading />
            ) : conversations.length === 0 ? (
              <EmptyState icon={MessageCircle} title="لا توجد محادثات بعد" />
            ) : (
              conversations.map((c) => (
                <button
                  key={c.phone}
                  onClick={() => openConversation(c)}
                  className={`flex w-full items-start gap-3 rounded-xl px-3 py-3 text-start transition-colors ${
                    selected?.phone === c.phone ? 'bg-accent-light' : 'hover:bg-paper'
                  }`}
                >
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-ink/5 text-sm font-bold text-ink">
                    {(c.contact_name || c.phone || '?').slice(0, 1)}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <p className="truncate text-sm font-medium text-ink">{c.contact_name || c.phone}</p>
                      <span className="shrink-0 text-[11px] text-ink-faint">{timeAgo(c.last_at)}</span>
                    </div>
                    <p className="truncate text-xs text-ink-soft">{c.last_message || '—'}</p>
                  </div>
                  {c.unread > 0 && <Badge tone="accent">{c.unread}</Badge>}
                </button>
              ))
            )}
          </div>
        </Card>

        <Card className="flex flex-col overflow-hidden lg:col-span-2">
          {!selected ? (
            <EmptyState icon={MessageCircle} title="اختر محادثة" description="اختر محادثة من القائمة لعرض الرسائل" />
          ) : (
            <>
              <div className="mb-3 flex items-center gap-3 border-b border-line pb-3">
                <div className="flex h-9 w-9 items-center justify-center rounded-full bg-ink/5 text-sm font-bold text-ink">
                  {(selected.contact_name || selected.phone || '?').slice(0, 1)}
                </div>
                <div>
                  <p className="text-sm font-medium text-ink">{selected.contact_name || selected.phone}</p>
                  <p className="text-xs text-ink-soft" dir="ltr">{selected.phone}</p>
                </div>
              </div>

              <div className="flex-1 space-y-2 overflow-y-auto scrollbar-thin px-1">
                {loadingThread ? (
                  <PageLoading />
                ) : (
                  messages.map((m) => (
                    <div key={m.id} className={`flex ${m.direction === 'outgoing' ? 'justify-start' : 'justify-end'}`}>
                      <div
                        className={`max-w-[75%] rounded-2xl px-3.5 py-2 text-sm ${
                          m.direction === 'outgoing' ? 'bg-ink text-white' : 'bg-paper text-ink'
                        }`}
                      >
                        <p className="whitespace-pre-wrap">{m.message_content}</p>
                        <p className={`mt-1 text-[10px] ${m.direction === 'outgoing' ? 'text-white/50' : 'text-ink-faint'}`}>
                          {timeAgo(m.created_at)}
                        </p>
                      </div>
                    </div>
                  ))
                )}
                <div ref={bottomRef} />
              </div>

              <form onSubmit={handleSend} className="mt-3 flex gap-2 border-t border-line pt-3">
                <Input
                  value={draft}
                  onChange={(e) => setDraft(e.target.value)}
                  placeholder="اكتب رسالة..."
                  className="flex-1"
                />
                <Button type="submit" loading={sending} disabled={!draft.trim()}>
                  <Send className="h-4 w-4" />
                </Button>
              </form>
            </>
          )}
        </Card>
      </div>
    </div>
  );
}
