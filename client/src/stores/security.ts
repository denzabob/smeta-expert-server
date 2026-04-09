// src/stores/security.ts
// Block 6 — Pinia store for account security state
import { ref } from 'vue'
import { defineStore } from 'pinia'
import {
  securityApi,
  type AuthMethodProfile,
  type SecuritySession,
  type SecurityDevice,
} from '@/api/security'

export const useSecurityStore = defineStore('security', () => {
  const authStatus = ref<AuthMethodProfile | null>(null)
  const sessions = ref<SecuritySession[]>([])
  const devices = ref<SecurityDevice[]>([])

  const loading = ref(false)
  const sessionsLoading = ref(false)
  const devicesLoading = ref(false)

  // ── Fetch ────────────────────────────────────────────────────────────────

  async function fetchAuthStatus() {
    loading.value = true
    try {
      authStatus.value = await securityApi.getAuthStatus()
    } finally {
      loading.value = false
    }
  }

  async function fetchSessions() {
    sessionsLoading.value = true
    try {
      const result = await securityApi.getSessions()
      sessions.value = result.sessions
    } finally {
      sessionsLoading.value = false
    }
  }

  async function fetchDevices() {
    devicesLoading.value = true
    try {
      const result = await securityApi.getDevices()
      devices.value = result.trusted_devices
    } finally {
      devicesLoading.value = false
    }
  }

  // ── Session actions ──────────────────────────────────────────────────────

  async function revokeSession(id: string) {
    await securityApi.revokeSession(id)
    sessions.value = sessions.value.filter((s) => s.id !== id)
  }

  async function revokeOtherSessions() {
    await securityApi.revokeOtherSessions()
    sessions.value = sessions.value.filter((s) => s.current)
  }

  // ── Device actions ───────────────────────────────────────────────────────

  async function revokeDevice(id: number) {
    await securityApi.revokeDevice(id)
    devices.value = devices.value.filter((d) => d.id !== id)
    if (authStatus.value) {
      authStatus.value.trusted_devices.count = Math.max(
        0,
        authStatus.value.trusted_devices.count - 1,
      )
    }
  }

  async function revokeAllDevices(stepUpToken: string) {
    await securityApi.revokeAllDevices(stepUpToken)
    devices.value = []
    // Refresh auth status (trusted_devices.count updated)
    await fetchAuthStatus()
  }

  // ── After bootstrap: refresh status ─────────────────────────────────────

  async function refreshAfterBootstrap() {
    await fetchAuthStatus()
  }

  return {
    authStatus,
    sessions,
    devices,
    loading,
    sessionsLoading,
    devicesLoading,
    fetchAuthStatus,
    fetchSessions,
    fetchDevices,
    revokeSession,
    revokeOtherSessions,
    revokeDevice,
    revokeAllDevices,
    refreshAfterBootstrap,
  }
})
