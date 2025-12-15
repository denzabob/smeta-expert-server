<template>
  <div class="main-layout">
    <aside class="sidebar">
      <nav>
        <ul>
          <li><router-link to="/">Главная</router-link></li>
          <li><router-link to="/materials">Материалы</router-link></li>
          <li><router-link to="/fittings">Фурнитура</router-link></li>
          <li><router-link to="/smeta">Смета</router-link></li>
        </ul>
      </nav>
    </aside>
    <main class="content">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.main-layout {
  display: flex;
  font-family: Arial, sans-serif;
}

.sidebar {
  width: 200px;
  background-color: #808080;
  padding: 20px;
}

.sidebar nav ul {
  list-style-type: none;
  padding: 0;
  margin: 0;
}

.sidebar nav ul li {
  margin-bottom: 10px;
}

.sidebar nav ul li a {
  text-decoration: none;
  color: white;
}

.sidebar nav ul li a.router-link-exact-active {
  font-weight: bold;
}

.content {
  flex-grow: 1;
  padding: 20px;
}
</style>
