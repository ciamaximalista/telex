// ecosystem.config.cjs
module.exports = {
  apps: [

  {
      name: "rss-translator",
      script: "./rss_translator.js",
      exec_mode: "fork",
      watch: false,
      autorestart: true,
      restart_delay: 2000,
      max_memory_restart: "250M",
      env: {
        NODE_ENV: "production",
        // Ruta del fichero de entorno privado (JSON) dentro de data/
        ENV_FILE: "./data/pm2_env.json"
      },
      out_file: "./logs/rss-translator.out.log",
      error_file: "./logs/rss-translator.err.log",
      log_date_format: "YYYY-MM-DD HH:mm:ss"
    }
  ]
};
