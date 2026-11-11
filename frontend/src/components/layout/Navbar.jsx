import { NavLink, useNavigate } from 'react-router-dom'
import { Moon, Sun } from 'lucide-react'
import { useAuth } from '../../context/useAuth'
import { usePreferences } from '../../context/usePreferences'
import api from '../../services/api'

export default function Navbar() {
  const navigate = useNavigate()
  const { logout } = useAuth()
  const { locale, setLocale, theme, toggleTheme, t } = usePreferences()

  const userName =
    localStorage.getItem('user_name') ||
    JSON.parse(localStorage.getItem('user') || '{}')?.name ||
    'المستخدم'

  const handleLogout = async () => {
    const token = localStorage.getItem('auth_token')

    try {
      if (token) {
        await api.post('/logout')
      }
    } catch (error) {
      console.error('Logout failed:', error)
    } finally {
      logout()
      navigate('/login', { replace: true })
    }
  }

  const linkClass = ({ isActive }) =>
    `rounded-lg px-3 py-2 text-sm font-medium transition ${
      isActive ? 'bg-slate-700 text-white shadow-sm' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
    }`

  return (
    <nav className="border-b border-slate-800 bg-slate-900 text-white shadow-md">
      <div className="mx-auto flex max-w-7xl items-center justify-between p-4">
        <NavLink to="/" className="text-lg font-bold tracking-wide text-amber-400">{t('Attendance System')}</NavLink>

        <div className="hidden items-center gap-2 md:flex">
          <NavLink to="/" className={linkClass}>{t('Dashboard')}</NavLink>
          <NavLink to="/attendance" className={linkClass}>{t('Attendance')}</NavLink>
          <NavLink to="/students" className={linkClass}>{t('Students')}</NavLink>
          <NavLink to="/reports" className={linkClass}>{t('Reports')}</NavLink>
        </div>

        <div className="flex items-center gap-3">
          <span className="hidden rounded-full border border-slate-700 bg-slate-800 px-3 py-1.5 text-sm font-medium text-slate-200 sm:inline-block">
            {userName}
          </span>
          <div className="flex items-center rounded-lg border border-slate-700 bg-slate-800 p-0.5" role="group" aria-label={t('Switch language')}>
            <button type="button" onClick={() => setLocale('en')} aria-pressed={locale === 'en'} className={`rounded-md px-2 py-1 text-xs font-semibold ${locale === 'en' ? 'bg-amber-400 text-slate-950' : 'text-slate-200'}`}>EN</button>
            <button type="button" onClick={() => setLocale('ar')} aria-pressed={locale === 'ar'} className={`rounded-md px-2 py-1 text-xs font-semibold ${locale === 'ar' ? 'bg-amber-400 text-slate-950' : 'text-slate-200'}`}>عربي</button>
          </div>
          <button
            type="button"
            onClick={toggleTheme}
            aria-label={t(theme === 'dark' ? 'Enable light mode' : 'Enable dark mode')}
            title={t(theme === 'dark' ? 'Enable light mode' : 'Enable dark mode')}
            className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-700 bg-slate-800 text-amber-300 transition hover:bg-slate-700"
          >
            {theme === 'dark' ? <Sun size={17} aria-hidden="true" /> : <Moon size={17} aria-hidden="true" />}
          </button>
          <button
            type="button"
            onClick={handleLogout}
            className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-500"
          >
            {t('Logout')}
          </button>
        </div>
      </div>

      <div className="flex gap-2 overflow-x-auto border-t border-slate-800 px-4 pb-3 pt-2 md:hidden">
        <NavLink to="/" className={linkClass}>{t('Dashboard')}</NavLink>
        <NavLink to="/attendance" className={linkClass}>{t('Attendance')}</NavLink>
        <NavLink to="/students" className={linkClass}>{t('Students')}</NavLink>
        <NavLink to="/reports" className={linkClass}>{t('Reports')}</NavLink>
      </div>
    </nav>
  )
}