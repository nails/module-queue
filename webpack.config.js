const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');
const {VueLoaderPlugin} = require('vue-loader');

module.exports = {
    entry: {
        'admin': './assets/js/admin.js',
    },
    output: {
        filename: '[name].min.js',
        path: path.resolve(__dirname, 'assets/js/')
    },
    resolve: {
        alias: {
            // Use Vue's ESM bundler build so vue-loader template helpers are bundled, not global
            'vue$': 'vue/dist/vue.esm-bundler.js'
        }
    },
    module: {
        rules: [
            {
                test: /\.vue$/,
                loader: 'vue-loader'
            },
            {
                test: /\.(css|scss|sass)$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    {
                        loader: 'css-loader',
                        options: {
                            url: false
                        }
                    },
                    'postcss-loader',
                    'sass-loader'
                ]
            }
        ]
    },
    plugins: [
        new MiniCssExtractPlugin({
            filename: '../css/[name].min.css'
        }),
        new VueLoaderPlugin(),

    ],
    mode: 'production'
};
