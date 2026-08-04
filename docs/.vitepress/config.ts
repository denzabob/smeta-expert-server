import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'База знаний',
  description: 'Документация по работе с сервисом PrismCore',
  base: '/docs/',
  lang: 'ru-RU',
  cleanUrls: true,
  srcExclude: ['*.md', '!index.md'],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Конфигуратор', link: '/configurator/' },
      { text: 'Аккаунт', link: '/account/login' },
      { text: 'Вернуться в сервис', link: 'https://app.prismcore.ru' },
    ],

    sidebar: [
      {
        text: 'Начало работы',
        items: [
          { text: 'Обзор', link: '/' },
        ],
      },
      {
        text: 'Конфигуратор',
        items: [
          { text: 'Обзор', link: '/configurator/' },
          { text: 'Создание заказа', link: '/configurator/create-order' },
          { text: 'Добавление изделия', link: '/configurator/add-window' },
          { text: 'Редактирование изделия', link: '/configurator/edit-window' },
          { text: 'Оформление заказа', link: '/configurator/checkout' },
        ],
      },
      {
        text: 'Аккаунт',
        items: [
          { text: 'Вход в систему', link: '/account/login' },
          { text: 'Безопасность профиля', link: '/account/security' },
        ],
      },
    ],

    search: {
      provider: 'local',
    },

    outline: {
      label: 'На этой странице',
      level: [2, 3],
    },

    docFooter: {
      prev: 'Предыдущая',
      next: 'Следующая',
    },

    darkModeSwitchLabel: 'Тема',
    sidebarMenuLabel: 'Меню',
    returnToTopLabel: 'Наверх',
    langMenuLabel: 'Выбрать язык',
  },
})
