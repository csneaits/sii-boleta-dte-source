// webpack.config.js
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');

module.exports = {
  mode: 'production',
  entry: {
    // Admin settings UI bundle
    admin: [
      path.resolve(__dirname, 'src/Presentation/assets/js/admin-settings.js'),
      path.resolve(__dirname, 'src/Presentation/assets/css/admin-settings.css'),
      path.resolve(__dirname, 'src/Presentation/assets/css/admin-shared.css'),
    ],
    // Control panel (logs/metrics/maintenance)
    controlPanel: [
      path.resolve(__dirname, 'src/Presentation/assets/js/control-panel.js'),
      path.resolve(__dirname, 'src/Presentation/assets/css/control-panel.css'),
      path.resolve(__dirname, 'src/Presentation/assets/css/admin-shared.css'),
    ],
    // CAF manager page
    cafManager: [
      path.resolve(__dirname, 'src/Presentation/assets/js/caf-manager.js'),
      path.resolve(__dirname, 'src/Presentation/assets/css/caf-manager.css'),
      path.resolve(__dirname, 'src/Presentation/assets/css/admin-shared.css'),
    ],
    // Generate DTE page
    generateDte: [
      path.resolve(__dirname, 'src/Presentation/assets/js/generate-dte.js'),
      path.resolve(__dirname, 'src/Presentation/assets/css/generate-dte.css'),
      path.resolve(__dirname, 'src/Presentation/assets/css/admin-shared.css'),
    ],
    // Frontend checkout enhancement (RUT field, etc.)
    checkoutRut: [
      path.resolve(__dirname, 'src/Presentation/assets/js/checkout-rut.js'),
    ],
  },
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].bundle.js',
    clean: true
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env']
          }
        }
      },
      {
        test: /\.css$/i,
        use: [MiniCssExtractPlugin.loader, 'css-loader']
      }
    ]
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: '[name].bundle.css'
    })
  ],
  optimization: {
    minimizer: [
      '...',
      new CssMinimizerPlugin(),
    ],
  },
  resolve: {
    extensions: ['.js']
  }
};
