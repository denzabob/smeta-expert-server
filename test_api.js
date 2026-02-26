import axios from 'axios'

const api = axios.create({
  baseURL: 'http://127.0.0.1:8001',
  headers: {
    'Accept': 'application/json',
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
})

async function checkProjectAPI() {
  try {
    const response = await api.get('/api/projects/4')
    console.log('Project data:')
    console.log(JSON.stringify(response.data, null, 2))
    console.log('Profile Rates:', response.data.profileRates)
  } catch (error) {
    console.error('Error:', error.message)
    console.error('Response:', error.response?.data)
  }
}

checkProjectAPI()
