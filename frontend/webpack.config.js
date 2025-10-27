const path = require("path");

module.exports = {
  entry: path.resolve(__dirname, "src", "index.jsx"),
  output: {
    filename: "bundle.js",
    path: path.resolve(__dirname, "../backend/public/static"),
    publicPath: "/static/",
    clean: true,
  },
  resolve: {
    extensions: [".js", ".jsx", ".json"]   // <- includes .jsx
  },
  module: {
    rules: [
      { test: /\.(js|jsx)$/, exclude: /node_modules/, use: "babel-loader" },
      { test: /\.css$/, use: ["style-loader", "css-loader"] }
    ],
  },
  devtool: "source-map",
};
