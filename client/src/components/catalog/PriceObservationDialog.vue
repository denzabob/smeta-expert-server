<template>
  <v-dialog v-model="dialog" max-width="550">
    <v-card>
      <v-card-title class="d-flex align-center">
        <v-icon class="mr-2">mdi-currency-rub</v-icon>
        Добавить наблюдение цены
        <v-spacer />
        <v-btn icon="mdi-close" variant="text" @click="close" />
      </v-card-title>

      <v-card-text>
        <v-alert type="info" variant="tonal" density="compact" class="mb-4">
          Укажите актуальную цену с подтверждением ссылкой на источник.
        </v-alert>

        <v-row dense>
          <v-col cols="12" sm="6">
            <v-text-field
              v-model.number="form.price_per_unit"
              label="Цена *"
              type="number"
              :rules="[rules.required, rules.positive]"
              suffix="₽"
              autofocus
            />
          </v-col>
          <v-col cols="12" sm="6">
            <v-select
              v-model="form.source_type"
              :items="sourceTypeOptions"
              item-title="label"
              item-value="value"
              label="Тип источника"
            />
          </v-col>
          <v-col cols="12">
            <v-text-field
              v-model="form.source_url"
              label="URL подтверждения *"
              placeholder="https://..."
              prepend-inner-icon="mdi-link"
              :rules="[rules.required, rules.url]"
            />
          </v-col>
          <v-col cols="12" sm="6">
            <v-select
              v-model="form.region_id"
              :items="regionOptions"
              item-title="label"
              item-value="value"
              label="Регион"
              clearable
            />
          </v-col>
          <v-col cols="12" sm="6">
            <v-text-field
              v-model="form.availability"
              label="Наличие"
              placeholder="В наличии / Под заказ"
            />
          </v-col>
        </v-row>
      </v-card-text>

      <v-divider />

      <v-card-actions class="pa-4">
        <v-spacer />
        <v-btn variant="text" @click="close">Отмена</v-btn>
        <v-btn
          color="primary"
          :loading="saving"
          :disabled="!isValid"
          @click="save"
        >
          <v-icon start>mdi-content-save</v-icon>
          Сохранить
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { useMaterialCatalogStore } from '@/stores/materialCatalog'
import type { AddObservationPayload } from '@/api/materialCatalog'

const props = defineProps<{
  modelValue: boolean
  materialId: number | null
  defaultRegionId?: number | null
  regions?: Array<{ id: number; region_name: string }>
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', val: boolean): void
  (e: 'saved'): void
}>()

const store = useMaterialCatalogStore()

const dialog = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const saving = ref(false)

const form = reactive<AddObservationPayload>({
  price_per_unit: 0,
  source_url: '',
  region_id: props.defaultRegionId ?? null,
  source_type: 'manual',
  availability: null,
})

const rules = {
  required: (v: any) => !!v || v === 0 || 'Обязательное поле',
  url: (v: string) => !v || /^https?:\/\/.+/.test(v) || 'Некорректный URL',
  positive: (v: number) => v >= 0 || 'Должно быть >= 0',
}

const sourceTypeOptions = [
  { label: 'Ручной ввод', value: 'manual' },
  { label: 'Веб-парсинг', value: 'web' },
  { label: 'Прайс-лист', value: 'price_list' },
  { label: 'Chrome-плагин', value: 'chrome_ext' },
]

const regionOptions = computed(() => {
  const opts: Array<{ label: string; value: number }> = []
  if (props.regions) {
    for (const r of props.regions) {
      opts.push({ label: r.region_name, value: r.id })
    }
  }
  return opts
})

const isValid = computed(() => {
  return form.price_per_unit >= 0 && !!form.source_url && /^https?:\/\/.+/.test(form.source_url)
})

async function save() {
  if (!isValid.value || !props.materialId) return
  saving.value = true
  try {
    await store.addObservation(props.materialId, form)
    emit('saved')
    close()
  } catch {
    // Error handled by store
  } finally {
    saving.value = false
  }
}

function close() {
  dialog.value = false
  Object.assign(form, {
    price_per_unit: 0,
    source_url: '',
    region_id: props.defaultRegionId ?? null,
    source_type: 'manual',
    availability: null,
  })
}

watch(() => props.defaultRegionId, (v) => {
  form.region_id = v ?? null
})
</script>
