<template>
  <div class="pin-input-container">
    <div class="pin-fields">
      <input
        v-for="(_, idx) in digits"
        :key="idx"
        :ref="el => setRef(el, idx)"
        v-model="digits[idx]"
        type="password"
        inputmode="numeric"
        maxlength="1"
        autocomplete="one-time-code"
        class="pin-digit"
        :class="{ 'pin-digit--error': hasError, 'pin-digit--filled': digits[idx] !== '' }"
        :disabled="disabled"
        @input="onInput(idx)"
        @keydown="onKeydown($event, idx)"
        @paste="onPaste($event)"
        @focus="onFocus(idx)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, nextTick, onMounted } from 'vue'

const props = defineProps<{
  modelValue?: string
  disabled?: boolean
  hasError?: boolean
  autofocus?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'complete', pin: string): void
}>()

const digits = ref<string[]>(['', '', '', ''])
const inputRefs = ref<(HTMLInputElement | null)[]>([null, null, null, null])

const setRef = (el: any, idx: number) => {
  inputRefs.value[idx] = el as HTMLInputElement
}

// Синхронизировать modelValue → digits
watch(
  () => props.modelValue,
  (val) => {
    if (val !== undefined) {
      const chars = val.split('')
      for (let i = 0; i < 4; i++) {
        digits.value[i] = chars[i] || ''
      }
    }
  },
  { immediate: true }
)

// Собрать pin из digits и emit
const emitValue = () => {
  const pin = digits.value.join('')
  emit('update:modelValue', pin)

  if (pin.length === 4 && /^\d{4}$/.test(pin)) {
    emit('complete', pin)
  }
}

const onInput = (idx: number) => {
  // Оставить только цифру
  const val = digits.value[idx]
  if (val && !/^\d$/.test(val)) {
    digits.value[idx] = ''
    return
  }

  emitValue()

  // Автопереход на следующий инпут
  if (val && idx < 3) {
    nextTick(() => {
      inputRefs.value[idx + 1]?.focus()
    })
  }
}

const onKeydown = (event: KeyboardEvent, idx: number) => {
  // Backspace: удалить текущий и перейти назад
  if (event.key === 'Backspace') {
    if (digits.value[idx] === '' && idx > 0) {
      event.preventDefault()
      digits.value[idx - 1] = ''
      emitValue()
      nextTick(() => {
        inputRefs.value[idx - 1]?.focus()
      })
    } else {
      digits.value[idx] = ''
      emitValue()
    }
  }

  // Стрелки
  if (event.key === 'ArrowLeft' && idx > 0) {
    event.preventDefault()
    inputRefs.value[idx - 1]?.focus()
  }
  if (event.key === 'ArrowRight' && idx < 3) {
    event.preventDefault()
    inputRefs.value[idx + 1]?.focus()
  }
}

const onPaste = (event: ClipboardEvent) => {
  event.preventDefault()
  const pasted = event.clipboardData?.getData('text') || ''
  const nums = pasted.replace(/\D/g, '').slice(0, 4)

  for (let i = 0; i < 4; i++) {
    digits.value[i] = nums[i] || ''
  }

  emitValue()

  // Фокус на последний заполненный или первый пустой
  nextTick(() => {
    const focusIdx = Math.min(nums.length, 3)
    inputRefs.value[focusIdx]?.focus()
  })
}

const onFocus = (idx: number) => {
  // Выбрать текст при фокусе
  nextTick(() => {
    inputRefs.value[idx]?.select()
  })
}

/** Очистить все поля и поставить фокус на первый. */
const clear = () => {
  digits.value = ['', '', '', '']
  emitValue()
  nextTick(() => {
    inputRefs.value[0]?.focus()
  })
}

/** Фокус на первый инпут. */
const focus = () => {
  nextTick(() => {
    inputRefs.value[0]?.focus()
  })
}

onMounted(() => {
  if (props.autofocus) {
    focus()
  }
})

defineExpose({ clear, focus })
</script>

<style scoped>
.pin-input-container {
  display: flex;
  justify-content: center;
}

.pin-fields {
  display: flex;
  gap: 12px;
}

.pin-digit {
  width: 56px;
  height: 64px;
  text-align: center;
  font-size: 28px;
  font-weight: 600;
  letter-spacing: 0;
  border: 2px solid #cfd4dc;
  border-radius: 12px;
  background: #eef1f4;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s;
  color: #1f2937;
  caret-color: #42a5f5;
}

.pin-digit:focus {
  border-color: #42a5f5;
  box-shadow: 0 0 0 3px rgba(66, 165, 245, 0.25);
  background: #f4f6f8;
}

.pin-digit--filled {
  border-color: #9aa5b1;
  background: #e3e8ee;
}

.pin-digit--error {
  border-color: #f44336;
  animation: shake 0.4s ease-in-out;
}

.pin-digit--error:focus {
  border-color: #f44336;
  box-shadow: 0 0 0 3px rgba(244, 67, 54, 0.15);
}

.pin-digit:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

:global(.dialog-overlay--dark) .pin-digit,
:global(.v-theme--dark) .pin-digit,
:global(.v-theme--myThemeDark) .pin-digit {
  border-color: rgba(255, 255, 255, 0.28);
  background: rgba(148, 163, 184, 0.18);
  color: #e5e7eb;
}

:global(.dialog-overlay--dark) .pin-digit--filled,
:global(.v-theme--dark) .pin-digit--filled,
:global(.v-theme--myThemeDark) .pin-digit--filled {
  border-color: rgba(255, 255, 255, 0.45);
  background: rgba(148, 163, 184, 0.26);
}

:global(.dialog-overlay--dark) .pin-digit:focus,
:global(.v-theme--dark) .pin-digit:focus,
:global(.v-theme--myThemeDark) .pin-digit:focus {
  background: rgba(148, 163, 184, 0.22);
}

@keyframes shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-4px); }
  40% { transform: translateX(4px); }
  60% { transform: translateX(-3px); }
  80% { transform: translateX(3px); }
}

@media (max-width: 600px) {
  .pin-fields {
    gap: 8px;
  }

  .pin-digit {
    width: min(56px, calc((100vw - 88px) / 4));
    height: 56px;
    font-size: 24px;
  }
}
</style>
