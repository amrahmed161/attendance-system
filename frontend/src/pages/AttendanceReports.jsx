import { useEffect, useEffectEvent, useMemo, useState } from 'react'
import * as XLSX from 'xlsx'
import api from '../services/api'
import { usePreferences } from '../context/usePreferences'

const gradeOptions = ['prep_1', 'prep_2', 'prep_3']
const previousSessionStatusConfig = {
  present: {
    label: 'Present',
    className: 'bg-emerald-600',
    printClass: 'status-present',
    color: '#16a34a',
  },
  absent: {
    label: 'Absent',
    className: 'bg-red-600',
    printClass: 'status-absent',
    color: '#dc2626',
  },
  new: {
    label: 'New student',
    className: 'bg-amber-500',
    printClass: 'status-new',
    color: '#f59e0b',
  },
}
const attendanceStatusConfig = {
  present: {
    label: 'Present',
    className: 'bg-emerald-600',
    printClass: 'status-present',
  },
  absent: {
    label: 'Absent',
    className: 'bg-red-600',
    printClass: 'status-absent',
  },
  late: {
    label: 'Late',
    className: 'bg-amber-500',
    printClass: 'status-late',
  },
}
const statusOptions = [
  { value: '', label: 'All' },
  { value: 'present', label: 'Present' },
  { value: 'absent', label: 'Absent' },
  { value: 'late', label: 'Late' },
]

function getLocalDateString() {
  const date = new Date()
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function formatDate(dateString, locale) {
  if (!dateString) return '-'
  const date = new Date(dateString)
  if (Number.isNaN(date.getTime())) return dateString
  return date.toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-CA')
}

function buildWhatsAppUrl(phone, studentName, date, locale) {
  if (!phone || phone === '-') return null

  const cleaned = String(phone).replace(/\D/g, '')
  const normalized = cleaned.startsWith('0') ? `20${cleaned.slice(1)}` : cleaned
  const messageText = locale === 'ar'
    ? `مرحبًا، نود إعلامكم بأن الطالب ${studentName} كان غائبًا في ${formatDate(date, locale)}. نرجو منكم متابعة الحالة. شكرًا لكم.`
    : `Hello, ${studentName} was marked absent on ${formatDate(date, locale)}. Please follow up. Thank you.`
  const message = encodeURIComponent(messageText)

  return `https://wa.me/${normalized}?text=${message}`
}

export default function AttendanceReports() {
  const { locale, t } = usePreferences()
  const [centers, setCenters] = useState([])
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [filters, setFilters] = useState(() => ({
    center_id: '',
    grade_level: '',
    status: '',
    from_date: '',
    to_date: getLocalDateString(),
  }))
  const setTranslatedError = useEffectEvent((message) => setError(t(message)))

  useEffect(() => {
    let active = true

    const loadCenters = async () => {
      try {
        const response = await api.get('/centers')
        if (active) setCenters(response?.data?.data || [])
      } catch (err) {
        if (active) console.error('Failed to load centers:', err)
      }
    }

    void loadCenters()
    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    let active = true

    const loadReports = async () => {
      try {
        const params = {}
        if (filters.center_id) params.center_id = filters.center_id
        if (filters.from_date) params.from_date = filters.from_date
        if (filters.to_date) params.to_date = filters.to_date

        const response = await api.get('/attendance/report', { params })
        if (active) setRecords(response?.data?.data || response?.data || [])
      } catch {
        if (active) {
          setTranslatedError('Unable to load attendance reports.')
          setRecords([])
        }
      } finally {
        if (active) setLoading(false)
      }
    }

    void loadReports()
    return () => {
      active = false
    }
  }, [filters.center_id, filters.from_date, filters.to_date])

  // 📍 هنا تم دمج كود المعالجة والفلترة كاملاً 📍
  const filteredRows = useMemo(() => {
    return records
      .map((record) => {
        const student = record.student || {}
        const studentName = student.name || record.student?.name || t('Unknown student')
        const centerName =
          student.center?.name || record.student?.center?.name || t('Unknown center')
        const gradeLevel = student.grade_level || record.student?.grade_level || t('Unknown')
        const parentPhone = student.parent_phone || record.student?.parent_phone || '-'
        const status = record.status || 'present'
        const previousSessionStatus = record.previous_status == null
          ? 'new'
          : record.previous_status === 'absent'
            ? 'absent'
            : 'present'

        return {
          ...record,
          studentName,
          centerName,
          gradeLevel,
          parentPhone,
          status,
          previousSessionStatus,
          missedClassStatus: t(previousSessionStatusConfig[previousSessionStatus].label),
          date: record.date,
          absence_reason: record.absence_reason || t('Not specified'),
          center_id: student.center_id || record.center_id || '',
        }
      })
      .filter((row) => {
        if (filters.grade_level && row.gradeLevel !== filters.grade_level) return false
        if (filters.status && row.status !== filters.status) return false
        if (filters.center_id) {
          const selectedCenterId = String(filters.center_id)
          const rowCenterId = String(row.center_id || '')
          if (rowCenterId && rowCenterId !== selectedCenterId) return false
        }
        return true
      })
  }, [records, filters, t])

  const exportExcel = () => {
    if (!filteredRows.length) return

    const workbookData = filteredRows.map((row) => ({
      [t('Student name')]: row.studentName,
      [t('Center name')]: row.centerName,
      [t('Grade')]: t(row.gradeLevel),
      [t('Attendance date')]: formatDate(row.date, locale),
      [t('Status')]: t(attendanceStatusConfig[row.status]?.label || 'Absent'),
      [t('Missed class status')]: row.missedClassStatus,
      [t('Absence reason')]: row.absence_reason,
      [t('Parent Phone')]: row.parentPhone,
    }))

    const worksheet = XLSX.utils.json_to_sheet(workbookData)
    const workbook = XLSX.utils.book_new()
    XLSX.utils.book_append_sheet(workbook, worksheet, t('Attendance Reports'))
    XLSX.writeFile(workbook, 'attendance_reports.xlsx')
  }

  const handlePrint = () => {
    const printWindow = window.open('', '_blank', 'width=1200,height=900')
    if (!printWindow) {
      alert(t('Please allow pop-ups to print.'))
      return
    }

    const rowsHtml = filteredRows
      .map(
        (row) => `
          <tr>
            <td>${row.studentName}</td>
            <td>${row.centerName}</td>
            <td>${row.gradeLevel}</td>
            <td>${formatDate(row.date, locale)}</td>
            <td>
              <span class="status-badge ${attendanceStatusConfig[row.status]?.printClass || 'status-absent'}">
                ${t(attendanceStatusConfig[row.status]?.label || 'Absent')}
              </span>
            </td>
            <td>
              <span class="status-badge ${previousSessionStatusConfig[row.previousSessionStatus].printClass}">
                ${row.missedClassStatus}
              </span>
            </td>
            <td>${row.absence_reason}</td>
            <td>${row.parentPhone}</td>
          </tr>
        `,
      )
      .join('')

    const html = `
      <!DOCTYPE html>
      <html lang="${locale}" dir="${locale === 'ar' ? 'rtl' : 'ltr'}">
        <head>
          <title>${t('Attendance Reports')}</title>
          <style>
            body { font-family: Arial, sans-serif; padding: 24px; color: #111827; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border: 1px solid #d1d5db; padding: 10px; text-align: center; font-size: 12px; }
            th { background: #e5e7eb; }
            .status-badge {
              display: inline-block;
              border-radius: 9999px;
              padding: 4px 10px;
              color: #ffffff;
              font-weight: 600;
              -webkit-print-color-adjust: exact;
              print-color-adjust: exact;
            }
            .status-present { background-color: #16a34a; }
            .status-absent { background-color: #dc2626; }
            .status-late, .status-new { background-color: #f59e0b; }
            h1 { margin: 0 0 10px; font-size: 24px; }
          </style>
        </head>
        <body>
          <h1>${t('Attendance Reports')}</h1>
          <table>
            <thead>
              <tr>
                <th>${t('Student name')}</th>
                <th>${t('Center name')}</th>
                <th>${t('Grade')}</th>
                <th>${t('Attendance date')}</th>
                <th>${t('Status')}</th>
                <th>${t('Missed class status')}</th>
                <th>${t('Absence reason')}</th>
                <th>${t('Parent Phone')}</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml || `<tr><td colspan="8">${t('No data matches the selected filters.')}</td></tr>`}
            </tbody>
          </table>
        </body>
      </html>
    `

    printWindow.document.write(html)
    printWindow.document.close()
    printWindow.focus()
    setTimeout(() => printWindow.print(), 300)
  }

  const resetFilters = () => {
    const nextFilters = {
      center_id: '',
      grade_level: '',
      status: '',
      from_date: '',
      to_date: getLocalDateString(),
    }
    if (
      filters.center_id !== nextFilters.center_id
      || filters.from_date !== nextFilters.from_date
      || filters.to_date !== nextFilters.to_date
    ) {
      setLoading(true)
      setError('')
    }
    setFilters(nextFilters)
  }

  const updateFilter = (key, value) => {
    if (
      filters[key] !== value
      && ['center_id', 'from_date', 'to_date'].includes(key)
    ) {
      setLoading(true)
      setError('')
    }
    setFilters((current) => ({ ...current, [key]: value }))
  }

  return (
    <div className="space-y-6">
      <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-sm font-medium text-sky-600">{t('Attendance Reports')}</p>
            <h1 className="mt-1 text-3xl font-bold text-slate-900">{t('Reports')}</h1>
          </div>

          <div className="flex flex-wrap gap-3">
            <button
              type="button"
              onClick={exportExcel}
              disabled={!filteredRows.length}
              className="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t('Export Excel')}
            </button>

            <button
              type="button"
              onClick={handlePrint}
              disabled={!filteredRows.length}
              className="rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {t('Print / PDF')}
            </button>

            <button
              type="button"
              onClick={resetFilters}
              className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            >
              {t('Reset filters')}
            </button>
          </div>
        </div>
      </div>

      <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
          <div>
            <label className="mb-2 block text-sm font-medium text-slate-700">{t('Center')}</label>
            <select
              value={filters.center_id}
              onChange={(e) => updateFilter('center_id', e.target.value)}
              className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
            >
              <option value="">{t('All')}</option>
              {centers.map((center) => (
                <option key={center.id} value={center.id}>
                  {center.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-slate-700">{t('Grade Level')}</label>
            <select
              value={filters.grade_level}
              onChange={(e) => updateFilter('grade_level', e.target.value)}
              className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
            >
              <option value="">{t('All')}</option>
              {gradeOptions.map((grade) => (
                <option key={grade} value={grade}>
                  {t(grade)}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-slate-700">{t('From date')}</label>
            <input
              type="date"
              value={filters.from_date}
              onChange={(e) => updateFilter('from_date', e.target.value)}
              className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-slate-700">{t('To date')}</label>
            <input
              type="date"
              value={filters.to_date}
              onChange={(e) => updateFilter('to_date', e.target.value)}
              className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
            />
          </div>

          <div>
            <label className="mb-2 block text-sm font-medium text-slate-700">{t('Status')}</label>
            <select
              value={filters.status}
              onChange={(e) => updateFilter('status', e.target.value)}
              className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
            >
              {statusOptions.map((status) => (
                <option key={status.value || 'all'} value={status.value}>
                  {t(status.label)}
                </option>
              ))}
            </select>
          </div>
        </div>
      </div>

      {error && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {error}
        </div>
      )}

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200 text-right">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Student name')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Center name')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Grade')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Attendance date')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Missed class status')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Status')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Absence reason')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Parent Phone')}</th>
                <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-600">{t('Notification')}</th>
              </tr>
            </thead>

            <tbody className="divide-y divide-slate-200 bg-white">
              {loading ? (
                <tr>
                  <td colSpan="9" className="px-4 py-10 text-center text-sm text-slate-500">
                    {t('Loading data...')}
                  </td>
                </tr>
              ) : filteredRows.length === 0 ? (
                <tr>
                  <td colSpan="9" className="px-4 py-10 text-center text-sm text-slate-500">
                    {t('No data matches the selected filters.')}
                  </td>
                </tr>
              ) : (
                filteredRows.map((row) => {
                  const waUrl = buildWhatsAppUrl(row.parentPhone, row.studentName, row.date, locale)

                  return (
                    <tr
                      key={row.id}
                    >
                      <td className="px-4 py-3 text-sm font-medium text-slate-700">{row.studentName}</td>
                      <td className="px-4 py-3 text-sm text-slate-700">{row.centerName}</td>
                      <td className="px-4 py-3 text-sm text-slate-700">{t(row.gradeLevel)}</td>
                      <td className="px-4 py-3 text-sm text-slate-700">{formatDate(row.date, locale)}</td>
                      <td className="px-4 py-3 text-sm">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold text-white ${previousSessionStatusConfig[row.previousSessionStatus].className}`}
                          style={{ backgroundColor: previousSessionStatusConfig[row.previousSessionStatus].color }}
                        >
                          {row.missedClassStatus}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-sm">
                        <span
                          className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold text-white ${attendanceStatusConfig[row.status]?.className || 'bg-red-600'}`}
                          style={{
                            backgroundColor: row.status === 'present'
                              ? '#16a34a'
                              : row.status === 'late'
                                ? '#f59e0b'
                                : '#dc2626',
                          }}
                        >
                          {t(attendanceStatusConfig[row.status]?.label || 'Absent')}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-sm text-slate-700">{row.absence_reason}</td>
                      <td className="px-4 py-3 text-sm text-slate-700">{row.parentPhone}</td>
                      <td className="px-4 py-3 text-sm">
                        {row.status === 'absent' && waUrl ? (
                          <a
                            href={waUrl}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex rounded-lg bg-green-500 px-3 py-2 text-xs font-medium text-white transition hover:bg-green-400"
                          >
                            {t('WhatsApp')}
                          </a>
                        ) : (
                          <span className="text-slate-400">-</span>
                        )}
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  )
}