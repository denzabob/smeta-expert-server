<template>
  <v-navigation-drawer :width="240" permanent>
    <v-list>
      <v-list-item to="/" prepend-icon="mdi-home">Главная</v-list-item>
      <v-list-item to="/materials" prepend-icon="mdi-layers">Материалы</v-list-item>
      <v-list-item to="/fittings" prepend-icon="mdi-cogs">Фурнитура</v-list-item>
      <v-list-item to="/smeta" prepend-icon="mdi-file-document">Смета</v-list-item>
    </v-list>
  </v-navigation-drawer>

  <v-app-bar color="primary" dark>
    <v-app-bar-title>СметаЭксперт Мебель</v-app-bar-title>
  </v-app-bar>

  <v-main>
    <v-container fluid>
      <router-view />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
// No script needed for this layout
</script>

<style scoped>
/* Scoped styles can be added here if needed */
</style>
