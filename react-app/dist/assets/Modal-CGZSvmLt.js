import{c as r,j as e,V as m,X as f,r as i,e as b}from"./index-Wc9gQ4gW.js";/**
 * @license lucide-react v1.58.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const x={name:"chevron-right",size:24,node:[["path",{d:"m9 18 6-6-6-6",key:"mthhwq"}]]};x.node;const j=r(x);/**
 * @license lucide-react v1.58.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const u={name:"eye-off",size:24,node:[["path",{d:"M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49",key:"ct8e1f"}],["path",{d:"M14.084 14.158a3 3 0 0 1-4.242-4.242",key:"151rxh"}],["path",{d:"M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143",key:"13bj9a"}],["path",{d:"m2 2 20 20",key:"1ooewy"}]]};u.node;const v=r(u);/**
 * @license lucide-react v1.58.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const h={name:"eye",size:24,node:[["path",{d:"M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0",key:"1nclc0"}],["circle",{cx:"12",cy:"12",r:"3",key:"1v7zrd"}]]};h.node;const g=r(h);/**
 * @license lucide-react v1.58.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y={name:"lock",size:24,node:[["rect",{width:"18",height:"11",x:"3",y:"11",rx:"2",ry:"2",key:"1w4ew1"}],["path",{d:"M7 11V7a5 5 0 0 1 10 0v4",key:"fwvmzm"}]]};y.node;const N=r(y);/**
 * @license lucide-react v1.58.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _={name:"mail",size:24,node:[["path",{d:"m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7",key:"132q7q"}],["rect",{x:"2",y:"4",width:"20",height:"16",rx:"2",key:"izxlao"}]]};_.node;const w=r(_);function E({variant:a="info",title:c,children:s,action:t,onDismiss:o,className:l="",shakeKey:n}){const d=m[a]||m.info;return e.jsxs("div",{className:`ux-alert ux-alert--${a}${n?" ux-shake":""} ${l}`,role:a==="error"?"alert":"status","data-no-toast":!0,children:[e.jsx("span",{className:"ux-alert__icon",children:e.jsx(d,{})}),e.jsxs("div",{className:"ux-alert__body",children:[c&&e.jsx("strong",{className:"ux-alert__title",children:c}),s&&e.jsx("div",{className:"ux-alert__msg",children:s}),t&&e.jsx("button",{type:"button",className:"ux-alert__action",onClick:t.onClick,children:t.label})]}),o&&e.jsx("button",{type:"button",className:"ux-alert__close","aria-label":"Dismiss",onClick:o,children:e.jsx(f,{})})]},n)}function L({tips:a,interval:c=6e3,label:s="Tip"}){const[t,o]=i.useState(0);return i.useEffect(()=>{if(a.length<2)return;const l=setInterval(()=>o(n=>(n+1)%a.length),c);return()=>clearInterval(l)},[a.length,c,t]),e.jsxs("div",{className:"ux-tip",role:"note","aria-live":"polite",children:[e.jsx("span",{className:"ux-tip__icon",children:e.jsx(b,{})}),e.jsxs("p",{className:"ux-tip__text",children:[e.jsxs("strong",{children:[s,":"]})," ",a[t]]},t),a.length>1&&e.jsx("button",{type:"button",className:"ux-tip__next","aria-label":"Next tip",onClick:()=>o(l=>(l+1)%a.length),children:e.jsx(j,{})})]})}function $({id:a,labelId:c,onClose:s,children:t}){const[o,l]=i.useState(!1);return i.useEffect(()=>{const n=requestAnimationFrame(()=>l(!0)),d=p=>p.key==="Escape"&&s();return document.addEventListener("keydown",d),document.body.classList.add("modal-open"),document.body.style.overflow="hidden",()=>{cancelAnimationFrame(n),document.removeEventListener("keydown",d),document.body.classList.remove("modal-open"),document.body.style.overflow=""}},[s]),e.jsxs(e.Fragment,{children:[e.jsx("div",{className:`modal fade modal-blur-effect${o?" show":""}`,id:a,tabIndex:"-1",role:"dialog","aria-modal":"true","aria-labelledby":c,style:{display:"block"},onClick:s,children:e.jsx("div",{className:"modal-dialog modal-dialog-centered",onClick:n=>n.stopPropagation(),children:e.jsx("div",{className:"modal-content",children:t})})}),e.jsx("div",{className:`modal-backdrop fade${o?" show":""}`})]})}export{E as A,v as E,N as L,$ as M,L as T,w as a,g as b};
