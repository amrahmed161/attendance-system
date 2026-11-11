import { createContext, useContext } from 'react'

export const PreferencesContext = createContext(null)

export function usePreferences() {
  const context = useContext(PreferencesContext)
  if (!context) throw new Error('usePreferences must be used inside PreferencesProvider')
  return context
}