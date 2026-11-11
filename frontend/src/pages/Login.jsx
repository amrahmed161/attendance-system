import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Eye, EyeOff, LoaderCircle, LockKeyhole, UserRound } from 'lucide-react'
import { useAuth } from '../context/useAuth'
import api from '../services/api'
import '../styles/login.css'

export default function Login() {
  const navigate = useNavigate()
  const { login } = useAuth()

  const [formData, setFormData] = useState({
    name: '',
    password: '',
  })
  const [showPassword, setShowPassword] = useState(false)
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

    const username = formData.name.trim()
    const password = formData.password

    if (!username || !password) {
      setError('يرجى إدخال اسم المستخدم وكلمة المرور.')
      return
    }

    setLoading(true)
    setError('')

    try {
      const response = await api.post('/login', {
        name: username,
        password,
      })

      const { token, user } = response.data || {}

      if (!token) {
        throw new Error('لم يتم استلام رمز المصادقة من الخادم.')
      }

      login({ token, user: user ?? { name: username } })
      navigate('/', { replace: true })
    } catch (err) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'فشل تسجيل الدخول. يرجى التحقق من اسم المستخدم وكلمة المرور.'

      setError(message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div dir="ltr" className="login-shell flex min-h-screen items-center justify-center px-4 py-8">
      <div className="login-card w-full max-w-md">
        <div className="login-logo">
          <div className="login-logo-icon">
            <UserRound className="h-7 w-7" />
          </div>
        </div>

        <div className="login-header">
          <span className="login-label">ATTENDANCE MANAGEMENT</span>
          <h1>Welcome back</h1>
          <p>Sign in to manage your attendance system</p>
        </div>

        {error && <div className="login-error">{error}</div>}

        <form onSubmit={handleSubmit} className="login-form" noValidate>
          <div className="login-field">
            <label htmlFor="name">Username or Email</label>
            <div className="login-input-wrapper">
              <UserRound className="login-input-icon" />
              <input
                id="name"
                name="name"
                type="text"
                value={formData.name}
                onChange={handleChange}
                placeholder="Enter your username or email"
                autoComplete="username"
                disabled={loading}
              />
            </div>
          </div>

          <div className="login-field">
            <label htmlFor="password">Password</label>
            <div className="login-input-wrapper">
              <LockKeyhole className="login-input-icon" />
              <input
                id="password"
                name="password"
                type={showPassword ? 'text' : 'password'}
                value={formData.password}
                onChange={handleChange}
                placeholder="Enter your password"
                autoComplete="current-password"
                disabled={loading}
              />

              <button
                type="button"
                aria-label={showPassword ? 'Hide password' : 'Show password'}
                onClick={() => setShowPassword((prev) => !prev)}
                disabled={loading}
                className="password-toggle"
              >
                {showPassword ? <EyeOff className="h-5 w-5" /> : <Eye className="h-5 w-5" />}
              </button>
            </div>
          </div>

          <button type="submit" disabled={loading} className="login-button">
            {loading ? (
              <>
                <LoaderCircle className="h-5 w-5 animate-spin" />
                Signing in...
              </>
            ) : (
              'Sign in'
            )}
          </button>
        </form>

        <div className="login-footer">
          <span>© 2026 Attendance Management System</span>
        </div>
      </div>
    </div>
  )
}