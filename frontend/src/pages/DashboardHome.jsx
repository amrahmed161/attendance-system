import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import api from '../services/api'
import { usePreferences } from '../context/usePreferences'

const dateKey = (date) => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const todayKey = dateKey(new Date())
const gradeOptions = ['prep_1', 'prep_2', 'prep_3']

const getDefaultDateRange = () => {
  const endDate = new Date()
  const startDate = new Date(endDate)
  startDate.setDate(startDate.getDate() - 6)
  return { fromDate: dateKey(startDate), toDate: dateKey(endDate) }
}

const getChartDays = (fromDate, toDate) => {
  if (!fromDate || !toDate || fromDate > toDate) return []

  const days = []
  const date = new Date(`${fromDate}T00:00:00`)
  const endDate = new Date(`${toDate}T00:00:00`)

  while (date <= endDate) {
    days.push({ key: dateKey(date), date: new Date(date) })
    date.setDate(date.getDate() + 1)
  }

  return days
}

export default function DashboardHome() {
  const { locale, t } = usePreferences()
  const [dateRange, setDateRange] = useState(getDefaultDateRange)
  const [centers, setCenters] = useState([])
  const [selectedCenterId, setSelectedCenterId] = useState('')
  const [selectedGrade, setSelectedGrade] = useState('')
  const [records, setRecords] = useState([])
  const [loadingCenters, setLoadingCenters] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const isValidDateRange = Boolean(
    dateRange.fromDate &&
    dateRange.toDate &&
    dateRange.fromDate <= dateRange.toDate
  )
  const visibleRecords = isValidDateRange ? records : []
  const isLoading = isValidDateRange && loading

  useEffect(() => {
    let active = true
    const loadCenters = async () => {
      try {
        const response = await api.get('/centers')
        if (!active) return
        setCenters(response?.data?.data || [])
      } catch {
        if (active) setError(t('Unable to load dashboard data.'))
      } finally {
        if (active) setLoadingCenters(false)
      }
    }

    loadCenters()
    return () => {
      active = false
    }
  }, [t])

  useEffect(() => {
    let active = true
    if (!isValidDateRange) {
      return () => {
        active = false
      }
    }

    const loadAttendance = async () => {
      setLoading(true)
      setError('')
      try {
        const response = await api.get('/attendance/report', {
          params: {
            from_date: dateRange.fromDate,
            to_date: dateRange.toDate,
            ...(selectedCenterId ? { center_id: selectedCenterId } : {}),
            ...(selectedGrade ? { grade_level: selectedGrade } : {}),
          },
        })

        if (active) setRecords(response?.data?.data || [])
      } catch {
        if (active) setError(t('Unable to load dashboard data.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    loadAttendance()
    return () => {
      active = false
    }
  }, [dateRange.fromDate, dateRange.toDate, isValidDateRange, selectedCenterId, selectedGrade, t])

  const chartData = getChartDays(dateRange.fromDate, dateRange.toDate).map(({ key, date }) => {
    const dayRecords = visibleRecords.filter((record) => String(record.date).slice(0, 10) === key)

    return {
      date: new Intl.DateTimeFormat(locale === 'ar' ? 'ar-EG' : 'en', {
        month: 'short',
        day: 'numeric',
      }).format(date),
      [t('Present')]: dayRecords.filter((record) => record.status === 'present').length,
      [t('Absent')]: dayRecords.filter((record) => record.status === 'absent').length,
      [t('Late')]: dayRecords.filter((record) => record.status === 'late').length,
      [t('New student')]: dayRecords.filter((record) => record.previous_status == null).length,
    }
  })

  const todayRecords = visibleRecords.filter((record) => String(record.date).slice(0, 10) === todayKey)
  const stats = [
    { label: t('Centers'), value: centers.length, color: 'border-sky-600' },
    { label: t("Today's attendance"), value: todayRecords.length, color: 'border-emerald-600' },
    { label: t('Present'), value: visibleRecords.filter((record) => record.status === 'present').length, color: 'border-teal-600' },
    { label: t('Absent'), value: visibleRecords.filter((record) => record.status === 'absent').length, color: 'border-rose-600' },
  ]

  return (
    <div className="space-y-6">
      <section className="flex flex-wrap items-end justify-between gap-4 border-b border-slate-200 pb-5">
        <div>
          <p className="text-sm font-semibold uppercase text-teal-700">{t('Date range')}</p>
          <h1 className="mt-1 text-3xl font-bold text-slate-900">{t('Attendance overview')}</h1>
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <div>
            <label htmlFor="dashboard-from-date" className="mb-1 block text-xs font-medium text-slate-600">{t('From date')}</label>
            <input
              id="dashboard-from-date"
              type="date"
              max={dateRange.toDate || undefined}
              value={dateRange.fromDate}
              onChange={(event) => setDateRange((prev) => ({ ...prev, fromDate: event.target.value }))}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            />
          </div>
          <div>
            <label htmlFor="dashboard-to-date" className="mb-1 block text-xs font-medium text-slate-600">{t('To date')}</label>
            <input
              id="dashboard-to-date"
              type="date"
              min={dateRange.fromDate || undefined}
              value={dateRange.toDate}
              onChange={(event) => setDateRange((prev) => ({ ...prev, toDate: event.target.value }))}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            />
          </div>
          <div>
            <label htmlFor="dashboard-center" className="mb-1 block text-xs font-medium text-slate-600">{t('Filter by Center')}</label>
            <select
              id="dashboard-center"
              value={selectedCenterId}
              onChange={(event) => setSelectedCenterId(event.target.value)}
              disabled={loadingCenters}
              className="min-w-44 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
              <option value="">{t('All Centers')}</option>
              {centers.map((center) => (
                <option key={center.id} value={center.id}>{center.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label htmlFor="dashboard-grade" className="mb-1 block text-xs font-medium text-slate-600">{t('Filter by Grade')}</label>
            <select
              id="dashboard-grade"
              value={selectedGrade}
              onChange={(event) => setSelectedGrade(event.target.value)}
              className="min-w-40 rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900"
            >
              <option value="">{t('All Grades')}</option>
              {gradeOptions.map((grade) => (
                <option key={grade} value={grade}>{t(grade)}</option>
              ))}
            </select>
          </div>
          <Link to="/attendance" className="rounded-lg bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">
            {t('Open attendance')}
          </Link>
          <Link to="/reports" className="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            {t('View reports')}
          </Link>
        </div>
      </section>

      {!isValidDateRange ? (
        <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {t('Please select a valid date range.')}
        </p>
      ) : error && (
        <p role="alert" className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      <section aria-label={t('Attendance overview')} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {stats.map((stat) => (
          <article key={stat.label} className={`min-h-28 rounded-lg border border-slate-200 border-s-4 ${stat.color} bg-white p-4`}>
            <p className="text-sm font-medium text-slate-600">{stat.label}</p>
            <p className="mt-3 text-3xl font-semibold tabular-nums text-slate-900">
              {isLoading ? '—' : stat.value.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en')}
            </p>
          </article>
        ))}
      </section>

      <section className="rounded-lg border border-slate-200 bg-white p-5">
        <div className="mb-5 flex items-center justify-between gap-4">
          <div>
            <h2 className="text-lg font-semibold text-slate-900">{t('Attendance by day')}</h2>
            <p className="mt-1 text-sm text-slate-500">{t('Total records')}: {isLoading ? '—' : visibleRecords.length.toLocaleString(locale === 'ar' ? 'ar-EG' : 'en')}</p>
          </div>
          {isLoading && <span className="text-sm text-slate-500">{t('Loading dashboard...')}</span>}
        </div>

        <div className="h-80 w-full" dir="ltr">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData} margin={{ top: 8, right: 12, left: 0, bottom: 4 }}>
              <CartesianGrid stroke="#e2e8f0" vertical={false} />
              <XAxis dataKey="date" tickLine={false} axisLine={false} />
              <YAxis allowDecimals={false} tickLine={false} axisLine={false} width={36} />
              <Tooltip />
              <Legend />
              <Bar dataKey={t('Present')} stackId="attendance" fill="#0f766e" radius={[3, 3, 0, 0]} />
              <Bar dataKey={t('Late')} stackId="attendance" fill="#d97706" />
              <Bar dataKey={t('Absent')} stackId="attendance" fill="#e11d48" radius={[3, 3, 0, 0]} />
              <Bar dataKey={t('New student')} fill="#f59e0b" radius={[3, 3, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>

        {!isLoading && visibleRecords.length === 0 && isValidDateRange && (
          <p className="text-center text-sm text-slate-500">{t('No attendance data for the last 7 days.')}</p>
        )}
      </section>

      <footer className="pb-2 pt-1 text-center text-sm text-slate-500">
        <p className="font-medium text-slate-700">The British Leader</p>
        <p className="mt-1">Ali Elkhateb</p>
      </footer>
    </div>
  )
}
