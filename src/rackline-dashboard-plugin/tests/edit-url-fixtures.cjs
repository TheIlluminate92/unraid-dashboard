const M=require('../source/usr/local/emhttp/plugins/rackline.dashboard/assets/js/model.js');
const paths=['/boot/config/my-demo.xml','/boot/config/my demo.xml','/boot/config/my+demo.xml','/boot/config/literal%2F.xml','/boot/config/50% plus+.xml'];
process.stdout.write(JSON.stringify(paths.map(path=>({path,url:M.editUrl(path)}))));
