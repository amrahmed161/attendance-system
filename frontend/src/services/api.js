import axios from 'axios'

const api = axios.create({
  baseURL: 'http://127.0.0.1:8000/api',
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
})

const pendingGetRequests = new Map()
const sendGet = api.get.bind(api)

api.get = (url, config = {}) => {
  const key = JSON.stringify([
    url,
    config.params || {},
    localStorage.getItem('auth_token') || '',
  ])
  const pendingRequest = pendingGetRequests.get(key)

  if (pendingRequest) return pendingRequest

  const request = sendGet(url, config).finally(() => pendingGetRequests.delete(key))
  pendingGetRequests.set(key, request)

  return request
}

api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('auth_token')

    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  (error) => Promise.reject(error)
)

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('auth_token')
      localStorage.removeItem('user')
      localStorage.removeItem('user_name')

      window.location.href = '/login'
    }

    return Promise.reject(error)
  }
)

export default api
