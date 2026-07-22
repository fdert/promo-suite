import { PageLoading, EmptyState } from './Surfaces';
import { Inbox } from 'lucide-react';

/**
 * columns: [{ key, header, render?(row), className? }]
 */
export default function DataTable({ columns, rows, loading, emptyTitle = 'لا توجد بيانات', emptyDescription, rowKey = 'id' }) {
  if (loading) return <PageLoading />;
  if (!rows || rows.length === 0) {
    return <EmptyState icon={Inbox} title={emptyTitle} description={emptyDescription} />;
  }

  return (
    <div className="overflow-x-auto rounded-2xl border border-line bg-white">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-line bg-paper/60">
            {columns.map((col) => (
              <th
                key={col.key}
                className={`whitespace-nowrap px-4 py-3 text-start text-xs font-semibold text-ink-faint ${col.className || ''}`}
              >
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-line">
          {rows.map((row) => (
            <tr key={row[rowKey]} className="transition-colors hover:bg-paper/60">
              {columns.map((col) => (
                <td key={col.key} className={`whitespace-nowrap px-4 py-3.5 text-ink ${col.className || ''}`}>
                  {col.render ? col.render(row) : row[col.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
