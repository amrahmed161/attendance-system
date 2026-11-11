import { Outlet } from 'react-router-dom'
import Navbar from './Navbar'
import { usePreferences } from '../../context/usePreferences'

export default function ProtectedLayout() {
  const { locale } = usePreferences()

  return (
    <div dir={locale === 'ar' ? 'rtl' : 'ltr'} className="app-shell">
      <Navbar />
      <main className="app-content">
        <Outlet />
      </main>
    </div>
  )
}