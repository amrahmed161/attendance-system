import { useCallback, useEffect, useEffectEvent, useRef, useState } from 'react'
import api from '../services/api'
import { usePreferences } from '../context/usePreferences'

const gradeOptions = ['prep_1', 'prep_2', 'prep_3']
const phonePattern = /^[0-9]{11}$/

const normalizePhoneInput = (value) => value.replace(/\D/g, '').slice(0, 11)
const studentCodeNumber = (barcode) => {
  const number = String(barcode || '').replace(/^STU-/, '')
  return /^\d+$/.test(number) ? Number(number) : Number.MAX_SAFE_INTEGER
}

const emptyCenterForm = { name: '' }
const emptyStudentForm = {
  name: '',
  student_phone: '',
  parent_phone: '',
  grade_level: 'prep_1',
  center_id: '',
}

export default function StudentsManager() {
  const { t } = usePreferences()
  const [centers, setCenters] = useState([])
  const [students, setStudents] = useState([])
  const [centerForm, setCenterForm] = useState(emptyCenterForm)
  const [editingCenterId, setEditingCenterId] = useState(null)
  const [studentForm, setStudentForm] = useState(emptyStudentForm)
  const [editingStudentId, setEditingStudentId] = useState(null)
  const [filters, setFilters] = useState({
    center_id: '',
    grade_level: '',
  })
  const [centerLoading, setCenterLoading] = useState(false)
  const [studentLoading, setStudentLoading] = useState(false)
  const [centerError, setCenterError] = useState('')
  const [studentError, setStudentError] = useState('')
  const centersRequestId = useRef(0)
  const studentsRequestId = useRef(0)
  const mounted = useRef(true)
  const translateError = useEffectEvent((message) => t(message))

  const fetchCenters = useCallback(async () => {
    const requestId = ++centersRequestId.current
    try {
      const response = await api.get('/centers')
      if (!mounted.current || requestId !== centersRequestId.current) return

      const centerList = response?.data?.data || []
      setCenters(centerList)

      if (centerList.length > 0) {
        setStudentForm((prev) => prev.center_id ? prev : {
          ...prev,
          center_id: String(centerList[0].id),
        })
      }
    } catch {
      if (mounted.current && requestId === centersRequestId.current) {
        setCenterError(t('Unable to load centers.'))
      }
    }
  }, [t])

  const fetchStudents = useCallback(async () => {
    const requestId = ++studentsRequestId.current
    try {
      const params = {}

      if (filters.center_id) params.center_id = filters.center_id
      if (filters.grade_level) params.grade_level = filters.grade_level

      const response = await api.get('/students', { params })
      if (!mounted.current || requestId !== studentsRequestId.current) return

      const studentList = response?.data?.data || []
      setStudents(studentList.sort((left, right) =>
        studentCodeNumber(left.barcode) - studentCodeNumber(right.barcode)
        || left.name.localeCompare(right.name),
      ))
    } catch {
      if (mounted.current && requestId === studentsRequestId.current) {
        setStudentError(t('Unable to load students.'))
      }
    }
  }, [filters.center_id, filters.grade_level, t])

  useEffect(() => {
    mounted.current = true
    let active = true
    const requestId = ++centersRequestId.current

    const loadCenters = async () => {
      try {
        const response = await api.get('/centers')
        if (!active || !mounted.current || requestId !== centersRequestId.current) return

        const centerList = response?.data?.data || []
        setCenters(centerList)
        if (centerList.length > 0) {
          setStudentForm((prev) => prev.center_id ? prev : {
            ...prev,
            center_id: String(centerList[0].id),
          })
        }
      } catch {
        if (active && mounted.current && requestId === centersRequestId.current) {
          setCenterError(translateError('Unable to load centers.'))
        }
      }
    }

    void loadCenters()
    return () => {
      active = false
      mounted.current = false
      centersRequestId.current += 1
      studentsRequestId.current += 1
    }
  }, [])

  useEffect(() => {
    let active = true
    const requestId = ++studentsRequestId.current

    const loadStudents = async () => {
      try {
        const params = {}
        if (filters.center_id) params.center_id = filters.center_id
        if (filters.grade_level) params.grade_level = filters.grade_level

        const response = await api.get('/students', { params })
        if (!active || !mounted.current || requestId !== studentsRequestId.current) return

        const studentList = response?.data?.data || []
        setStudents(studentList.sort((left, right) =>
          studentCodeNumber(left.barcode) - studentCodeNumber(right.barcode)
          || left.name.localeCompare(right.name),
        ))
      } catch {
        if (active && mounted.current && requestId === studentsRequestId.current) {
          setStudentError(translateError('Unable to load students.'))
        }
      }
    }

    void loadStudents()
    return () => {
      active = false
      studentsRequestId.current += 1
    }
  }, [filters.center_id, filters.grade_level])

  const handleCenterSubmit = async (e) => {
    e.preventDefault()
    setCenterError('')

    const name = centerForm.name.trim()
    if (!name) {
      setCenterError(t('Center name is required.'))
      return
    }

    setCenterLoading(true)

    try {
      if (editingCenterId) {
        const response = await api.put(`/centers/${editingCenterId}`, { name })
        const updatedCenter = response?.data?.data
        setCenters((prev) =>
          prev.map((center) => center.id === updatedCenter.id ? { ...center, ...updatedCenter } : center)
        )
        setEditingCenterId(null)
        setCenterForm(emptyCenterForm)
      } else {
        await api.post('/centers', { name })
        setCenterForm(emptyCenterForm)
        await fetchCenters()
      }
    } catch (error) {
      setCenterError(
        t(error?.response?.data?.message) ||
        t(editingCenterId ? 'Failed to update center.' : 'Failed to add center.')
      )
    } finally {
      setCenterLoading(false)
    }
  }

  const handleCenterEdit = (center) => {
    setEditingCenterId(center.id)
    setCenterForm({ name: center.name })
    setCenterError('')
  }

  const handleCenterCancelEdit = () => {
    setEditingCenterId(null)
    setCenterForm(emptyCenterForm)
    setCenterError('')
  }

  const handleCenterDelete = async (center) => {
    if (!window.confirm(t('Deleting this center will also permanently delete its students, attendance records, rooms, and groups. Continue?'))) {
      return
    }

    setCenterError('')
    setCenterLoading(true)

    try {
      await api.delete(`/centers/${center.id}`)
      const remainingCenters = centers.filter((item) => item.id !== center.id)
      setCenters(remainingCenters)
      setStudents((prev) => prev.filter((student) => Number(student.center_id) !== Number(center.id)))
      setStudentForm((prev) => ({
        ...prev,
        center_id: Number(prev.center_id) === Number(center.id)
          ? String(remainingCenters[0]?.id || '')
          : prev.center_id,
      }))
      if (Number(filters.center_id) === Number(center.id)) {
        setFilters((prev) => ({ ...prev, center_id: '' }))
      }
      if (editingCenterId === center.id) {
        handleCenterCancelEdit()
      }
    } catch (error) {
      setCenterError(t(error?.response?.data?.message) || t('Failed to delete center.'))
    } finally {
      setCenterLoading(false)
    }
  }

  const handleStudentSubmit = async (e) => {
    e.preventDefault()
    setStudentError('')

    if (!studentForm.center_id) {
      setStudentError(t('Please select a center.'))
      return
    }

    if (!studentForm.name.trim()) {
      setStudentError(t('Student name is required.'))
      return
    }

    const studentNameWordCount = studentForm.name.trim().split(/\s+/u).filter(Boolean).length
    if (studentNameWordCount < 2 || studentNameWordCount > 3) {
      setStudentError(t('Student name must contain two or three words.'))
      return
    }

    if (!studentForm.parent_phone.trim()) {
      setStudentError(t('Parent phone is required.'))
      return
    }

    if (studentForm.student_phone && !phonePattern.test(studentForm.student_phone.trim())) {
      setStudentError(t('Student phone must contain exactly 11 digits.'))
      return
    }

    if (!phonePattern.test(studentForm.parent_phone.trim())) {
      setStudentError(t('Parent phone must contain exactly 11 digits.'))
      return
    }

    setStudentLoading(true)

    try {
      const studentData = {
        ...studentForm,
        name: studentForm.name.trim(),
        student_phone: studentForm.student_phone.trim(),
        parent_phone: studentForm.parent_phone.trim(),
      }

      if (editingStudentId) {
        await api.put(`/students/${editingStudentId}`, studentData)
        setEditingStudentId(null)
      } else {
        await api.post('/students', studentData)
      }

      setStudentForm((prev) => ({
        ...emptyStudentForm,
        center_id: prev.center_id || '',
        grade_level: prev.grade_level || 'prep_1',
      }))
      await fetchStudents()
      await fetchCenters()
    } catch (error) {
      setStudentError(
        t(error?.response?.data?.message) ||
        t(editingStudentId ? 'Failed to update student.' : 'Failed to add student.'),
      )
    } finally {
      setStudentLoading(false)
    }
  }

  const handleStudentEdit = (student) => {
    setEditingStudentId(student.id)
    setStudentForm({
      name: student.name || '',
      student_phone: student.student_phone || '',
      parent_phone: student.parent_phone || '',
      grade_level: student.grade_level || 'prep_1',
      center_id: String(student.center_id || ''),
    })
    setStudentError('')
  }

  const handleStudentCancelEdit = () => {
    setEditingStudentId(null)
    setStudentForm((prev) => ({
      ...emptyStudentForm,
      center_id: prev.center_id || '',
      grade_level: prev.grade_level || 'prep_1',
    }))
    setStudentError('')
  }

  const handleStudentDelete = async (student) => {
    if (!window.confirm(t('Deleting this student will also permanently delete their attendance records. Continue?'))) {
      return
    }

    setStudentError('')
    setStudentLoading(true)

    try {
      await api.delete(`/students/${student.id}`)
      if (editingStudentId === student.id) {
        handleStudentCancelEdit()
      }
      await fetchStudents()
      await fetchCenters()
    } catch (error) {
      setStudentError(t(error?.response?.data?.message) || t('Failed to delete student.'))
    } finally {
      setStudentLoading(false)
    }
  }

  const centerNameMap = centers.reduce((acc, center) => {
    acc[center.id] = center.name
    return acc
  }, {})

  return (
    <div className="min-h-screen bg-slate-100 px-4 py-8 text-slate-800">
      <div className="mx-auto max-w-7xl space-y-8">
        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-2xl font-bold text-slate-900">{t('Schools & Students Manager')}</h1>
        </div>

        <div className="grid gap-8 lg:grid-cols-2">
          <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="mb-5 text-xl font-semibold text-slate-900">{t('Centers')}</h2>

            <form onSubmit={handleCenterSubmit} className="space-y-4">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">{t('Center Name')}</label>
                <input
                  type="text"
                  value={centerForm.name}
                  onChange={(e) =>
                    setCenterForm((prev) => ({ ...prev, name: e.target.value }))
                  }
                  className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                  placeholder={t('e.g. Al Noor Center')}
                />
              </div>

              {centerError && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {centerError}
                </div>
              )}

              <button
                type="submit"
                disabled={centerLoading}
                className="inline-flex w-full items-center justify-center rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-sky-700 disabled:cursor-not-allowed disabled:bg-sky-400"
              >
                {centerLoading ? t('Saving...') : t(editingCenterId ? 'Update Center' : 'Add Center')}
              </button>
              {editingCenterId && (
                <button
                  type="button"
                  onClick={handleCenterCancelEdit}
                  disabled={centerLoading}
                  className="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {t('Cancel')}
                </button>
              )}
            </form>

            <div className="mt-6">
              <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
                {t('Existing Centers')}
              </h3>

              <div className="space-y-3">
                {centers.length === 0 ? (
                  <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                    {t('No centers yet.')}
                  </div>
                ) : (
                  centers.map((center) => (
                    <div
                      key={center.id}
                      className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                      <div>
                        <p className="font-medium text-slate-900">{center.name}</p>
                      </div>
                      <div className="flex flex-wrap items-center gap-2">
                        {gradeOptions.map((grade) => (
                          <span
                            key={grade}
                            className="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700"
                          >
                            {t(grade)}: {center[`${grade}_count`] ?? 0}
                          </span>
                        ))}
                        <button
                          type="button"
                          onClick={() => handleCenterEdit(center)}
                          disabled={centerLoading}
                          className="text-sm font-medium text-sky-700 transition hover:text-sky-900 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {t('Edit')}
                        </button>
                        <button
                          type="button"
                          onClick={() => handleCenterDelete(center)}
                          disabled={centerLoading}
                          className="text-sm font-medium text-red-600 transition hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                          {t('Delete')}
                        </button>
                      </div>
                    </div>
                  ))
                )}
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="mb-5 text-xl font-semibold text-slate-900">{t('Students')}</h2>

            <form onSubmit={handleStudentSubmit} className="space-y-4">
              <div>
                <label className="mb-2 block text-sm font-medium text-slate-700">{t('Student Name (2 or 3 words)')}</label>
                <input
                  type="text"
                  value={studentForm.name}
                  onChange={(e) =>
                    setStudentForm((prev) => ({ ...prev, name: e.target.value }))
                  }
                  className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                  placeholder={t('e.g. Ahmed Omar')}
                />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-2 block text-sm font-medium text-slate-700">{t('Student Phone (11 digits)')}</label>
                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={11}
                    value={studentForm.student_phone}
                    onChange={(e) =>
                      setStudentForm((prev) => ({
                        ...prev,
                        student_phone: normalizePhoneInput(e.target.value),
                      }))
                    }
                    className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                    placeholder="01012345678"
                  />
                </div>

                <div>
                  <label className="mb-2 block text-sm font-medium text-slate-700">{t('Parent Phone (11 digits)')}</label>
                  <input
                    type="text"
                    inputMode="numeric"
                    maxLength={11}
                    value={studentForm.parent_phone}
                    onChange={(e) =>
                      setStudentForm((prev) => ({
                        ...prev,
                        parent_phone: normalizePhoneInput(e.target.value),
                      }))
                    }
                    className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                    placeholder="01123456789"
                  />
                </div>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <div>
                  <label className="mb-2 block text-sm font-medium text-slate-700">{t('Grade Level')}</label>
                  <select
                    value={studentForm.grade_level}
                    onChange={(e) =>
                      setStudentForm((prev) => ({ ...prev, grade_level: e.target.value }))
                    }
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
                  <label className="mb-2 block text-sm font-medium text-slate-700">{t('Center')}</label>
                  <select
                    value={studentForm.center_id}
                    onChange={(e) =>
                      setStudentForm((prev) => ({ ...prev, center_id: e.target.value }))
                    }
                    className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
                  >
                    {centers.length === 0 ? (
                      <option value="">{t('No centers available')}</option>
                    ) : (
                      centers.map((center) => (
                        <option key={center.id} value={center.id}>
                          {center.name}
                        </option>
                      ))
                    )}
                  </select>
                </div>
              </div>

              {studentError && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                  {studentError}
                </div>
              )}

              <button
                type="submit"
                disabled={studentLoading || centers.length === 0}
                className="inline-flex w-full items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-emerald-400"
              >
                {studentLoading ? t('Saving...') : t(editingStudentId ? 'Save Changes' : 'Add Student')}
              </button>
              {editingStudentId && (
                <button
                  type="button"
                  onClick={handleStudentCancelEdit}
                  disabled={studentLoading}
                  className="inline-flex w-full items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {t('Cancel')}
                </button>
              )}
            </form>
          </section>
        </div>

        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <div className="mb-5 flex flex-col gap-4 md:flex-row md:items-end">
            <div className="flex-1">
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Filter by Center')}</label>
              <select
                value={filters.center_id}
                onChange={(e) =>
                  setFilters((prev) => ({ ...prev, center_id: e.target.value }))
                }
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
              >
                <option value="">{t('All Centers')}</option>
                {centers.map((center) => (
                  <option key={center.id} value={center.id}>
                    {center.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex-1">
              <label className="mb-2 block text-sm font-medium text-slate-700">{t('Filter by Grade')}</label>
              <select
                value={filters.grade_level}
                onChange={(e) =>
                  setFilters((prev) => ({ ...prev, grade_level: e.target.value }))
                }
                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm text-slate-900 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-200"
              >
                <option value="">{t('All Grades')}</option>
                {gradeOptions.map((grade) => (
                  <option key={grade} value={grade}>
                    {t(grade)}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="overflow-hidden rounded-xl border border-slate-200">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Student Code')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Name')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Student Phone')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Parent Phone')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Grade')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Center')}
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                      {t('Actions')}
                    </th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-slate-200 bg-white">
                  {students.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-500">
                        {t('No students found for the selected filters.')}
                      </td>
                    </tr>
                  ) : (
                    students.map((student) => (
                      <tr
                        key={student.id}
                      >
                        <td className="px-4 py-3 text-sm font-mono text-slate-700">
                          {typeof student.barcode === 'string' && /^STU-\d+$/.test(student.barcode)
                            ? String(Number(student.barcode.slice(4)))
                            : student.barcode || '-'}
                        </td>
                        <td className="px-4 py-3 text-sm font-medium text-slate-700">
                          {student.name}
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-700">
                          {student.student_phone || '-'}
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-700">
                          {student.parent_phone || '-'}
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-700">
                          {t(student.grade_level)}
                        </td>
                        <td className="px-4 py-3 text-sm text-slate-700">
                          {centerNameMap[student.center_id] || t('Unknown')}
                        </td>
                        <td className="px-4 py-3 text-sm">
                          <div className="flex items-center gap-3">
                            <button
                              type="button"
                              onClick={() => handleStudentEdit(student)}
                              disabled={studentLoading}
                              className="font-medium text-sky-700 transition hover:text-sky-900 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                              {t('Edit')}
                            </button>
                            <button
                              type="button"
                              onClick={() => handleStudentDelete(student)}
                              disabled={studentLoading}
                              className="font-medium text-red-600 transition hover:text-red-800 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                              {t('Delete')}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
    </div>
  )
}