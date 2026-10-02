import { useState } from 'react'
import axios from 'axios'

const API_URL = 'http://127.0.0.1:8000/api/login'

export default function Login({ onLoginSuccess }) {
  const [formData, setFormData] = useState({
    name: '',
    password: '',
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const handleChange = (event) => {
    const { name, value } = event.target

    setFormData((prev) => ({
      ...prev,
      [name]: value,
    }))

    if (error) {
      setError('')
    }
  }

  const handleSubmit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const response = await axios.post(API_URL, formData)
      const { token, user } = response.data

      localStorage.setItem('auth_token', token)
      localStorage.setItem('user', JSON.stringify(user ?? { name: formData.name }))
      localStorage.setItem('user_name', user?.name ?? formData.name)

      if (onLoginSuccess) {
        onLoginSuccess({ token, user: user ?? { name: formData.name } })
        return
      }

      window.location.href = '/dashboard'
    } catch (err) {
      const message =
        err.response?.data?.message ||
        'اسم المستخدم أو كلمة المرور غير صحيحة. حاول مرة أخرى.'

      setError(message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="min-h-screen bg-slate-950 px-4 py-10 text-slate-100">
      <div className="mx-auto flex min-h-[calc(100vh-5rem)] max-w-6xl items-center justify-center">
        <div
          dir="rtl"
          className="grid w-full max-w-5xl overflow-hidden rounded-[30px] border border-slate-800 bg-slate-900/80 shadow-2xl shadow-slate-950/70 backdrop-blur-xl lg:grid-cols-2"
        >
          <div className="relative hidden bg-gradient-to-br from-sky-600 via-cyan-500 to-emerald-400 p-10 lg:flex lg:flex-col lg:justify-between">
            <div>
              <span className="inline-flex rounded-full border border-white/30 bg-white/10 px-3 py-1 text-xs font-semibold tracking-[0.2em] text-sky-50 uppercase">
                Attendance System
              </span>
            </div>

            <div>
              <h1 className="text-4xl font-black leading-tight text-white">
                نظام حضور
                <br />
                المعلمين
              </h1>
              <p className="mt-4 max-w-sm text-sm text-sky-50/80">
                تسجيل دخول موثوق وسريع للاستخدام دون اتصال، مصمم لبيئة عمل مدرسية
                منظمة ومريحة.
              </p>
            </div>

            <div className="rounded-2xl border border-white/20 bg-white/10 p-4 text-sm text-sky-50/90">
              <p className="font-medium">معلومات الحضور</p>
              <p className="mt-1 text-sky-50/80">تتبع الحضور بسهولة في أي وقت.</p>
            </div>
          </div>

          <div className="p-6 sm:p-8 lg:p-10">
            <div className="mb-8 text-center lg:text-right">
              <p className="text-sm font-medium text-sky-300">English Teacher Attendance</p>
              <h2 className="mt-2 text-3xl font-bold text-white">تسجيل الدخول</h2>
            </div>

            {error && (
              <div className="mb-5 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
                {error}
              </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-5">
              <div>
                <label htmlFor="name" className="mb-2 block text-sm font-medium text-slate-200">
                  اسم المستخدم
                </label>
                <input
                  id="name"
                  name="name"
                  type="text"
                  value={formData.name}
                  onChange={handleChange}
                  placeholder="ادخل اسم المستخدم"
                  autoComplete="username"
                  className="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-sm text-white placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
                  required
                />
              </div>

              <div>
                <label htmlFor="password" className="mb-2 block text-sm font-medium text-slate-200">
                  كلمة المرور
                </label>
                <input
                  id="password"
                  name="password"
                  type="password"
                  value={formData.password}
                  onChange={handleChange}
                  placeholder="أدخل كلمة المرور"
                  autoComplete="current-password"
                  className="w-full rounded-xl border border-slate-700 bg-slate-800/80 px-4 py-3 text-sm text-white placeholder:text-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
                  required
                />
              </div>

              <button
                type="submit"
                disabled={loading}
                className="flex w-full items-center justify-center rounded-xl bg-sky-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:bg-sky-500/60"
              >
                {loading ? 'جاري تسجيل الدخول...' : 'تسجيل الدخول'}
              </button>
            </form>

            <div className="mt-6 text-center text-xs text-slate-400">
              Offline Desktop Application • Secure Access
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
