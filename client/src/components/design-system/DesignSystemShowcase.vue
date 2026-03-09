<template>
  <v-card class="pa-4">
    <div class="text-subtitle-1 font-weight-semibold mb-3">SaaS Design System Showcase</div>

    <v-row dense>
      <v-col cols="12" md="6">
        <v-text-field label="Поиск" prepend-inner-icon="mdi-magnify" />
      </v-col>
      <v-col cols="12" md="6">
        <v-select
          label="Статус"
          :items="[
            { title: 'NEW', value: 'NEW' },
            { title: 'PLANNED', value: 'PLANNED' },
            { title: 'REJECTED', value: 'REJECTED' },
            { title: 'IMPLEMENTED', value: 'IMPLEMENTED' },
          ]"
        />
      </v-col>
    </v-row>

    <div class="d-flex align-center ga-3 my-2">
      <v-switch label="Автоматический режим" />
      <v-btn color="primary">Сохранить</v-btn>
      <v-btn variant="tonal">Отмена</v-btn>
    </div>

    <v-list class="rounded-lg mt-3" lines="one">
      <v-list-item prepend-icon="mdi-lightbulb-on-outline" title="Идея #1" />
      <v-list-item prepend-icon="mdi-shield-check-outline" title="Проверка #2" />
      <v-list-item prepend-icon="mdi-cog-outline" title="Настройка #3" />
    </v-list>
  </v-card>
</template>
