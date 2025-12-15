<template>
  <div>
    <h1>Расчёт сметы мебели</h1>
    <div>
      <label for="moduleName">Название модуля: </label>
      <input type="text" id="moduleName" v-model="moduleName" />
    </div>
    <hr />
    <h2>Детали</h2>
    <table>
      <thead>
        <tr>
          <th>Наименование</th>
          <th>Ширина (мм)</th>
          <th>Высота (мм)</th>
          <th>Материал</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(detail, index) in details" :key="index">
          <td><input type="text" v-model="detail.name" /></td>
          <td><input type="number" v-model="detail.width" /></td>
          <td><input type="number" v-model="detail.height" /></td>
          <td>
            <select v-model="detail.material">
              <option v-for="material in materials" :key="material" :value="material">
                {{ material }}
              </option>
            </select>
          </td>
        </tr>
      </tbody>
    </table>
    <button @click="addDetail">+ Добавить деталь</button>
    <hr />
    <h3>Итого: {{ total }}</h3>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';

interface Detail {
  name: string;
  width: number;
  height: number;
  material: string;
}

const moduleName = ref('');
const details = ref<Detail[]>([]);
const materials = ref(["ЛДСП Egger", "МДФ эмаль"]);
const total = ref(0);

const addDetail = () => {
  details.value.push({
    name: '',
    width: 0,
    height: 0,
    material: materials.value[0],
  });
};
</script>
