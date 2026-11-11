import { useEffect, useEffectEvent, useMemo, useState } from 'react'
import api from '../services/api'
import { usePreferences } from '../context/usePreferences'

const gradeOptions = ['prep_1', 'prep_2', 'prep_3']

const today = new Date().toISOString().slice(0, 10)

export default function AttendanceChecklist() {
  const { t } = usePreferences()
  const [centers, setCenters] = useState([])
  const [students, setStudents] = useState([])
  const [selectedCenterId, setSelectedCenterId] = useState('')
  const [selectedGrade, setSelectedGrade] = useState('prep_1')
  const [selectedDate, setSelectedDate] = useState(today)
  const [studentSearch, setStudentSearch] = useState('')
  const [loadingCenters, setLoadingCenters] = useState(true)
  const [loadingStudents, setLoadingStudents] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  const [attendanceMap, setAttendanceMap] = useState({})
  const setTranslatedError = useEffectEvent((message) => setError(t(message)))

  useEffect(() => {
    let active = true

    const loadCenters = async () => {
      try {
        const response = await api.get('/centers')
        if (!active) return

        const centerList = response?.data?.data || []
        setCenters(centerList)
        setSelectedCenterId((current) => current || String(centerList[0]?.id || ''))
        if (centerList.length === 0) setLoadingStudents(false)
      } catch {
        if (active) {
          setTranslatedError('Unable to load centers.')
          setLoadingStudents(false)
        }
      } finally {
        if (active) setLoadingCenters(false)
      }
    }

    void loadCenters()
    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (!selectedCenterId) return undefined

    let active = true

    const loadStudents = async () => {
      try {
        const response = await api.get('/students', {
          params: {
            center_id: selectedCenterId,
            grade_level: selectedGrade,
          },
        })
        if (!active) return

        const studentList = response?.data?.data || []
        setStudents(studentList)
        setAttendanceMap((current) => {
          const nextMap = {}
          studentList.forEach((student) => {
            nextMap[student.id] = current[student.id] || {
              student_id: student.id,
              status: 'present',
              absence_reason: '',
            }
          })
          return nextMap
        })
      } catch {
        if (active) setTranslatedError('Unable to load students.')
      } finally {
        if (active) setLoadingStudents(false)
      }
    }

    void loadStudents()
    return () => {
      active = false
    }
  }, [selectedCenterId, selectedGrade])

  const selectedCenterName = useMemo(() => {
    return centers.find((center) => String(center.id) === String(selectedCenterId))?.name || t('Center')
  }, [centers, selectedCenterId, t])

  const filteredStudents = useMemo(() => {
    const normalizeCode = (value) => String(value || '')
      .replace(/^STU-/i, '')
      .replace(/^0+(?=\d)/, '')
      .toLocaleLowerCase()
    const query = normalizeCode(studentSearch.trim())

    if (!query) return students

    return students.filter((student) => (
      student.name.toLocaleLowerCase().includes(query)
      || normalizeCode(student.barcode).includes(query)
    ))
  }, [studentSearch, students])

  const updateStatus = (studentId, status) => {
    setAttendanceMap((prev) => ({
      ...prev,
      [studentId]: {
        ...prev[studentId],
        student_id: studentId,
        status,
        absence_reason: status === 'absent' ? prev[studentId]?.absence_reason || '' : '',
      },
    }))
  }

  const updateAbsenceReason = (studentId, value) => {
    setAttendanceMap((prev) => ({
      ...prev,
      [studentId]: {
        ...prev[studentId],
        student_id: studentId,
        absence_reason: value,
      },
    }))
  }

  const handleSaveAttendance = async () => {
    if (!selectedCenterId) {
      setError(t('Please select a center.'))
      return
    }

    const records = students.map((student) => {
      const record = attendanceMap[student.id] || {
        student_id: student.id,
        status: 'present',
        absence_reason: '',
      }

      return {
        student_id: student.id,
        status: record.status || 'present',
        absence_reason:
          record.status === 'absent' ? (record.absence_reason || '').trim() : null,
      }
    })

    if (!records.length) {
      setError(t('No students available for the selected filters.'))
      return
    }

    setSaving(true)
    setError('')
    setSuccess('')

    try {
      await api.post('/attendance/record', {
        date: selectedDate,
        records,
      })

      setSuccess(t('Attendance saved successfully.'))
    } catch (err) {
      setError(t(err?.response?.data?.message) || t('Failed to save attendance.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-100 px-4 py-8 text-slate-800">
      <div className="mx-auto max-w-7xl">
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="mb-6 flex items-center justify-between">
            <h1 className="text-2xl font-bold text-slate-900">{t('Daily Attendance')}</h1>
          </div>

          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Center')}</label>
              <select
                value={selectedCenterId}
                onChange={(e) => {
                  const centerId = e.target.value
                  setSelectedCenterId(centerId)
                  if (centerId) {
                    setLoadingStudents(true)
                  } else {
                    setStudents([])
                    setLoadingStudents(false)
                  }
                }}
                disabled={loadingCenters}
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200 disabled:cursor-not-allowed"
              >
                {loadingCenters ? (
                  <option value="">{t('Loading centers...')}</option>
                ) : centers.length === 0 ? (
                  <option value="">{t('No centers found')}</option>
                ) : (
                  centers.map((center) => (
                    <option key={center.id} value={center.id}>
                      {center.name}
                    </option>
                  ))
                )}
              </select>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Grade Level')}</label>
              <select
                value={selectedGrade}
                onChange={(e) => {
                  setSelectedGrade(e.target.value)
                  setLoadingStudents(true)
                }}
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
              >
                {gradeOptions.map((grade) => (
                  <option key={grade} value={grade}>
                    {t(grade)}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Date')}</label>
              <input
                type="date"
                value={selectedDate}
                onChange={(e) => setSelectedDate(e.target.value)}
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
              />
            </div>

            <div>
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Search Students')}</label>
              <input
                type="text"
                value={studentSearch}
                onChange={(e) => setStudentSearch(e.target.value)}
                placeholder={t('Search by student name or code')}
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
              />
            </div>
          </div>

          {(error || success) && (
            <div className="mt-5">
              {error && (
                <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                  {error}
                </div>
              )}
              {success && (
                <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                  {success}
                </div>
              )}
            </div>
          )}
        </div>

        <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="mb-5 flex items-center justify-between gap-4">
            <div>
              <h2 className="text-xl font-semibold text-slate-900">
                {selectedCenterName} - {t(selectedGrade)}
              </h2>
            </div>

            <button
              type="button"
              onClick={handleSaveAttendance}
              disabled={saving || loadingStudents || !selectedCenterId}
              className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-2.5 text-sm font-medium text-white transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-sky-400"
            >
              {saving ? t('Saving...') : t('Save Attendance')}
            </button>
          </div>

          {loadingStudents ? (
            <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              {t('Loading students...')}
            </div>
          ) : students.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              {t('No students found for this center and grade.')}
            </div>
          ) : filteredStudents.length === 0 ? (
            <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
              {t('No students match your search.')}
            </div>
          ) : (
            <div className="space-y-3">
              {filteredStudents.map((student) => {
                const record = attendanceMap[student.id] || {
                  student_id: student.id,
                  status: 'present',
                  absence_reason: '',
                }

                return (
                  <div
                    key={student.id}
                    className="flex flex-col gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 md:flex-row md:items-center md:justify-between"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-base font-semibold text-slate-900">{student.name}</p>
                      <p className="text-sm text-slate-500">
                        {student.student_phone || t('No student phone')} • {student.parent_phone || t('No parent phone')}
                      </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        onClick={() => updateStatus(student.id, 'present')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                          record.status === 'present'
                            ? 'bg-emerald-600 text-white shadow-sm'
                            : 'bg-white text-emerald-700 ring-1 ring-emerald-200 hover:bg-emerald-50'
                        }`}
                      >
                        {t('Present')}
                      </button>

                      <button
                        type="button"
                        onClick={() => updateStatus(student.id, 'absent')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                          record.status === 'absent'
                            ? 'bg-red-600 text-white shadow-sm'
                            : 'bg-white text-red-700 ring-1 ring-red-200 hover:bg-red-50'
                        }`}
                      >
                        {t('Absent')}
                      </button>

                      <button
                        type="button"
                        onClick={() => updateStatus(student.id, 'late')}
                        className={`rounded-lg px-3 py-2 text-sm font-medium transition ${
                          record.status === 'late'
                            ? 'bg-yellow-500 text-white shadow-sm'
                            : 'bg-white text-yellow-700 ring-1 ring-yellow-200 hover:bg-yellow-50'
                        }`}
                      >
                        {t('Late')}
                      </button>
                    </div>

                    {record.status === 'absent' && (
                      <div className="w-full md:max-w-xs">
                        <input
                          type="text"
                          value={record.absence_reason || ''}
                          onChange={(e) => updateAbsenceReason(student.id, e.target.value)}
                          placeholder={t('Reason for absence')}
                          className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                        />
                      </div>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}