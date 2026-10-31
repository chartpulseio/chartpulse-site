/* ============================================================================
   Header v7 - original cinematic space hero with Earth video + canvas FX
   ============================================================================ */
(function(){
var hero=document.querySelector('.cp-hero7'),v=document.getElementById('cp-hero7-video'),c=document.getElementById('fx7');
if(!c||!c.getContext)return;
if(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches){if(v){v.removeAttribute('autoplay');v.pause();}return;}
var x=c.getContext('2d'),W=0,H=0,AH=0,G={mx:0,my:0},stars=[],rings=[],shoot=null,nextRing=1800,nextShoot=3200,run=true,raf=0,
m={x:0,y:0,tx:0,ty:0,a:0,ta:0},par={x:0,y:0};
function geo(){if(!hero||!v)return;var h=hero.getBoundingClientRect(),r=v.getBoundingClientRect(),s=Math.max(r.width/1280,r.height/720);
 G.mx=1120*s+r.left-h.left+(r.width-1280*s)*.85;G.my=170*s+r.top-h.top;AH=Math.min(H,r.bottom-h.top);}
function size(){var d=Math.min(window.devicePixelRatio||1,2);if(!hero)return;W=hero.clientWidth;H=hero.clientHeight;c.width=W*d;c.height=H*d;x.setTransform(d,0,0,d,0,0);geo();
 var n=Math.min(70,Math.round(W*AH/20000));stars=[];
 for(var i=0;i<n;i++)stars.push({x:Math.random()*W,y:Math.random()*AH,r:.5+Math.random()*.7,p:Math.random()*6.28,s:.0007+Math.random()*.0016,b:i%12===0});}
function flare(px,py,r,a){var g=x.createRadialGradient(px,py,0,px,py,r*.55);
 g.addColorStop(0,'rgba(255,244,205,'+a+')');g.addColorStop(1,'rgba(255,244,205,0)');
 x.fillStyle=g;x.beginPath();x.arc(px,py,r*.55,0,6.283);x.fill();
 x.strokeStyle='rgba(255,240,200,'+a*.7+')';x.lineWidth=1;x.beginPath();x.moveTo(px-r,py);x.lineTo(px+r,py);x.moveTo(px,py-r);x.lineTo(px,py+r);x.stroke();}
function frame(t){raf=0;if(!run)return;
 x.clearRect(0,0,W,H);x.globalCompositeOperation='lighter';
 m.x+=(m.tx-m.x)*.1;m.y+=(m.ty-m.y)*.1;m.a+=(m.ta-m.a)*.08;
 par.x+=((m.ta?(.5-m.tx/W)*14:0)-par.x)*.06;par.y+=((m.ta?(.5-m.ty/H)*8:0)-par.y)*.06;
 if(v)v.style.transform='translate3d('+par.x.toFixed(2)+'px,'+par.y.toFixed(2)+'px,0)';
 var cx=G.mx+par.x,cy=G.my+par.y,i,s,a;
 for(i=0;i<stars.length;i++){s=stars[i];a=.5+.5*Math.sin(t*s.s+s.p);
  if(s.b)flare(s.x,s.y,5+10*a,a*.85);
  else{x.fillStyle='rgba(205,224,255,'+(.12+.5*a)+')';x.beginPath();x.arc(s.x,s.y,s.r,0,6.283);x.fill();}}
 a=.5+.5*Math.sin(t/650);var g=x.createRadialGradient(cx,cy,0,cx,cy,190);
 g.addColorStop(0,'rgba(215,235,255,'+(.1+.07*a)+')');g.addColorStop(1,'rgba(215,235,255,0)');x.fillStyle=g;x.fillRect(cx-190,cy-190,380,380);
 if(t>nextRing){rings.push(t);nextRing=t+5200;}
 rings=rings.filter(function(q){return t-q<4600;});
 rings.forEach(function(q){var k=(t-q)/4600,r=45+k*Math.min(W*.62,900),o=(1-k)*(1-k);
  x.beginPath();x.arc(cx,cy,r,0,6.283);x.lineWidth=8;x.strokeStyle='rgba(120,200,255,'+o*.05+')';x.stroke();
  x.lineWidth=1.3;x.strokeStyle='rgba(195,232,255,'+o*.4+')';x.stroke();});
 if(!shoot&&t>nextShoot){var an=Math.PI*(.11+Math.random()*.1);shoot={x:W*(.4+Math.random()*.55),y:AH*Math.random()*.3,vx:-Math.cos(an)*.9,vy:Math.sin(an)*.9,t:t};}
 if(shoot){var k2=t-shoot.t;if(k2>950){shoot=null;nextShoot=t+3500+Math.random()*4500;}
  else{var hx=shoot.x+shoot.vx*k2,hy=shoot.y+shoot.vy*k2,al=Math.sin(k2/950*Math.PI),lg=x.createLinearGradient(hx-shoot.vx*170,hy-shoot.vy*170,hx,hy);
   lg.addColorStop(0,'rgba(255,255,255,0)');lg.addColorStop(1,'rgba(255,246,220,'+al+')');
   x.strokeStyle=lg;x.lineWidth=1.6;x.beginPath();x.moveTo(hx-shoot.vx*170,hy-shoot.vy*170);x.lineTo(hx,hy);x.stroke();flare(hx,hy,9,al*.8);}}
 if(m.a>.01){var cg=x.createRadialGradient(m.x,m.y,0,m.x,m.y,200);
  cg.addColorStop(0,'rgba(255,232,170,'+.2*m.a+')');cg.addColorStop(.35,'rgba(120,200,255,'+.07*m.a+')');cg.addColorStop(1,'rgba(120,200,255,0)');
  x.fillStyle=cg;x.fillRect(m.x-200,m.y-200,400,400);}
 var y0=H-3,bx=((t%7500)/7500)*(W+400)-200,fa=Math.max(0,Math.min(1,Math.min(bx+80,W+80-bx)/200)),hg=x.createLinearGradient(0,0,W,0);
 hg.addColorStop(0,'rgba(90,216,255,0)');hg.addColorStop(.5,'rgba(90,216,255,.45)');hg.addColorStop(1,'rgba(90,216,255,0)');
 x.strokeStyle=hg;x.lineWidth=1;x.beginPath();x.moveTo(0,y0);x.lineTo(W,y0);x.stroke();
 var P=[[-60,0],[-44,0],[-34,-4],[-24,0],[-12,6],[0,-30],[10,12],[22,0],[44,-7],[64,0]];
 x.save();x.globalAlpha=fa;x.shadowColor='#f5c451';x.shadowBlur=12;x.strokeStyle='rgba(255,226,150,.9)';x.lineWidth=1.8;x.beginPath();
 P.forEach(function(p,j){j?x.lineTo(bx+p[0],y0+p[1]):x.moveTo(bx+p[0],y0+p[1]);});x.stroke();x.restore();
 flare(bx,y0-30,12,.9*fa);
 raf=requestAnimationFrame(frame);}
if(hero){hero.addEventListener('pointermove',function(e){if(e.pointerType!=='mouse')return;var r=hero.getBoundingClientRect();m.tx=e.clientX-r.left;m.ty=e.clientY-r.top;if(!m.ta){m.x=m.tx;m.y=m.ty;}m.ta=1;});
hero.addEventListener('pointerleave',function(){m.ta=0;});}
if(window.ResizeObserver)new ResizeObserver(size).observe(hero||c);else addEventListener('resize',size);
size();
if('IntersectionObserver'in window)new IntersectionObserver(function(e){run=e[0].isIntersecting;if(run&&!raf)raf=requestAnimationFrame(frame);}).observe(hero||c);
raf=requestAnimationFrame(frame);
})();