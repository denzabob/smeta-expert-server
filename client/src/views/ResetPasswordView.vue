<template>
  <v-container class="fill-height" fluid>
    <v-row align="center" justify="center">
      <v-col cols="12" sm="8" md="5" lg="4">
        <v-card>
          <v-card-title class="text-h6">Смена пароля</v-card-title>
          <v-card-text>
            <AuthReset />
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup lang="ts">
import AuthReset from '@/components/auth/AuthReset.vue'
</script>
