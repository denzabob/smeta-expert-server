<!-- IosToggle.vue -->
<template>
  <div
    class="ios-toggle"
    :class="{ 'is-checked': Boolean(modelValue), 'is-disabled': disabled }"
    :style="{ cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1 }"
    @click="!disabled && $emit('update:modelValue', !Boolean(modelValue))"
  >
    <div class="ios-toggle-thumb"></div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  modelValue: boolean | number
  disabled?: boolean
}>()

defineEmits<{
  'update:modelValue': [value: boolean]
}>()
</script>

<style scoped>
.ios-toggle {
  width: 50px;
  height: 30px;
  background-color: #e5e5ea; /* Цвет выключенного состояния (серый iOS) */
  border-radius: 16px;
  position: relative;
  cursor: pointer;
  transition: background-color 0.2s ease;
  user-select: none;
}

.ios-toggle.is-checked {
  background-color: #34c759; /* Зелёный цвет iOS при включении */
}

.ios-toggle-thumb {
  position: absolute;
  top: 2px;
  left: 2px;
  width: 26px;
  height: 26px;
  background-color: white;
  border-radius: 50%;
  transition: transform 0.2s ease;
  box-shadow:
    0 2px 4px rgba(0, 0, 0, 0.2),
    0 1px 1px rgba(0, 0, 0, 0.1);
}

.ios-toggle.is-checked .ios-toggle-thumb {
  transform: translateX(20px);
}
</style>
